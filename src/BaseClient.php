<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://doc.hyperf.io
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace Hyperf\GrpcClient;

use Google\Protobuf\Internal\Message;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Grpc\Parser;
use Hyperf\Grpc\StatusCode;
use Hyperf\GrpcClient\Exception\GrpcClientException;
use Hyperf\GrpcClient\Exception\RequestErrorException;
use Hyperf\Utils\ApplicationContext;
use Hyperf\Utils\ChannelPool;
use Hyperf\Utils\Context;
use InvalidArgumentException;

/**
 * @method int send(Request $request)
 * @method mixed recv(int $streamId, float $timeout = null)
 * @method bool close($yield = false)
 */
class BaseClient
{
    /**
     * @var null|GrpcClient
     */
    private $grpcClient;

    /**
     * @var array
     */
    private $options;

    /**
     * @var string
     */
    private $hostname;

    private $package;

    private $headers;

    /**
     * @var bool
     */
    private $initialized = false;

    private $interface;

    /**
     * @Inject()
     * @var GrpcInterfaceManager
     */
    protected $interfaceManager;

    public function __construct(string $serviceName, string $hostname, array $options = [])
    {

        $this->package = $serviceName;
        $this->hostname = $hostname;
        $this->options = $options['options'] ?? [];
        $this->interface = $options['service_interface'] ?? [];
        $this->headers = $options['headers'] ?:Context::get('grpc.headers');
    }

    public function __destruct()
    {
        if ($this->grpcClient) {
            $this->grpcClient->close(true);
        }
    }

    public function __get($name)
    {
        if (!$this->initialized) {
            $this->init();
        }
        return $this->getGrpcClient()->{$name};
    }

    public function __call($name, $arguments)
    {
        if (!$this->initialized) {
            $this->init();
        }

        return $this->getGrpcClient()->{$name}(...$arguments);
    }

    public function start()
    {
        $client = $this->grpcClient;
        return $client->isRunning() || $client->start();
    }

    public function getGrpcClient(): GrpcClient
    {
        if (!$this->initialized) {
            $this->init();
        }
        return $this->grpcClient;
    }

    protected function init()
    {
        if (!empty($this->options['client'])) {
            if (!($this->options['client'] instanceof GrpcClient)) {
                throw new InvalidArgumentException('Parameter client have to instanceof Hyperf\GrpcClient\GrpcClient');
            }
            $this->grpcClient = $this->options['client'];
        } else {
            $this->grpcClient = new GrpcClient(ApplicationContext::getContainer()->get(ChannelPool::class));
            $this->grpcClient->set($this->hostname, $this->options);
        }
        if (!$this->start()) {
            $message = sprintf(
                'Grpc client start failed with error code %d when connect to %s',
                $this->grpcClient->getErrCode(),
                $this->hostname
            );
            throw new GrpcClientException($message, StatusCode::INTERNAL);
        }
        $this->initialized = true;
    }

    /**
     * Call a remote method that takes a single argument and has a
     * single output.
     *
     * @param string $method The name of the method to call
     * @param Message $argument The argument to the method
     * @param callable $deserialize A function that deserializes the response
     * @return array|\Google\Protobuf\Internal\Message[]|\swoole_http2_response[]
     * @throws GrpcClientException
     */
    public function simpleRequest(
        string $method,
        Message $argument
    )
    {
        $url = $this->package . $method;

        $streamId = retry($this->options['retry_attempts'] ?? 3, function () use ($url, $argument) {
            $streamId = $this->send($this->buildRequest($url, $argument));
            if ($streamId === 0) {
                $this->init();
                // The client should not be used after this exception
                throw new GrpcClientException('Failed to send the request to server', StatusCode::INTERNAL);
            }
            return $streamId;
        }, $this->options['retry_interval'] ?? 100);

        return Parser::parseResponse($this->recv($streamId), [$this->interfaceManager->getResponse($this->interface, $method), 'decode']);

    }

    public function doSend(string $method, Message $message)
    {

        list($reply, $status) = $this->simpleRequest($method, $message);

        if ($status !== 0) {
            throw new RequestErrorException($this->package . $method . ' 请求失败,错误信息为: ' . $reply . '状态码: ' . $status);
        }

        return $reply;
    }

    /**
     * Call a remote method that takes a stream of arguments and has a single
     * output.
     *
     * @param string $method The name of the method to call
     * @param callable $deserialize A function that deserializes the response
     *
     * @return ClientStreamingCall The active call object
     */
    protected function clientStreamRequest(
        string $method,
        $deserialize
    ): ClientStreamingCall
    {
        $call = new ClientStreamingCall();
        $call->setClient($this->grpcClient)
            ->setMethod($method)
            ->setDeserialize($deserialize);

        return $call;
    }

    /**
     * Call a remote method with messages streaming in both directions.
     *
     * @param string $method The name of the method to call
     * @param callable $deserialize A function that deserializes the responses
     */
    protected function _bidiRequest(
        string $method,
        $deserialize
    ): BidiStreamingCall
    {
        $call = new BidiStreamingCall();
        $call->setClient($this->grpcClient)
            ->setMethod($method)
            ->setDeserialize($deserialize);

        return $call;
    }

    protected function buildRequest(string $method, Message $argument): Request
    {
        return new Request($method, $argument, $this->headers);
    }
}
