<?php

use React\Datagram\Socket;
use React\Promise\When;
use React\Promise\PromiseInterface;

class SocketTest extends TestCase
{
    private $factory;

    public function setUp()
    {
        $this->loop = React\EventLoop\Factory::create();
        $this->factory = new React\Datagram\Factory($this->loop, $this->createResolverMock());
    }

    public function testCreateClientCloseWillNotBlock()
    {
        $promise = $this->factory->createClient('127.0.0.1:12345');
        $client = $this->getValueFromResolvedPromise($promise);

        $client->send('test');
        $client->close();

        $this->loop->run();

        return $client;
    }

    /**
     *
     * @param Socket $client
     * @depends testCreateClientCloseWillNotBlock
     */
    public function testClientCloseAgainWillNotBlock(Socket $client)
    {
        $client->close();
        $this->loop->run();
    }

    public function testCreateClientEndWillNotBlock()
    {
        $promise = $this->factory->createClient('127.0.0.1:12345');
        $client = $this->getValueFromResolvedPromise($promise);

        $client->send('test');
        $client->end();

        $this->loop->run();

        return $client;
    }

    /**
     *
     * @param Socket $client
     * @depends testCreateClientEndWillNotBlock
     */
    public function testClientEndAgainWillNotBlock(Socket $client)
    {
        $client->end();
        $this->loop->run();

        return $client;
    }

    /**
     *
     * @param Socket $client
     * @depends testClientEndAgainWillNotBlock
     */
    public function testClientSendAfterEndIsNoop(Socket $client)
    {
        $client->send('does not matter');
        $this->loop->run();
    }

    public function testClientSendHugeWillFail()
    {
        $promise = $this->factory->createClient('127.0.0.1:12345');
        $client = $this->getValueFromResolvedPromise($promise);

        $client->send(str_repeat(1, 1024 * 1024));
        $client->on('error', $this->expectCallableOnce());
        $client->end();

        $this->loop->run();
    }

    public function testClientSendNoServerWillFail()
    {
        $promise = $this->factory->createClient('127.0.0.1:1234');
        $client = $this->getValueFromResolvedPromise($promise);

        // send a message to a socket that is not actually listening
        // expect the remote end to reject this by sending an ICMP message
        // which we will receive as an error message. This depends on the
        // host to actually reject UDP datagrams, which not all systems do.
        $client->send('hello');
        $client->on('error', $this->expectCallableOnce());

        $loop = $this->loop;
        $client->on('error', function () use ($loop) {
             $loop->stop();
        });

        $that = $this;
        $this->loop->addTimer(1.0, function () use ($that, $loop) {
            $loop->stop();
            $that->markTestSkipped('UDP packet was not rejected after 0.5s, ignoring test');
        });

        $this->loop->run();
    }

    public function testCreatePair()
    {
        $promise = $this->factory->createServer('127.0.0.1:0');
        $server = $this->getValueFromResolvedPromise($promise);

        $promise = $this->factory->createClient($server->getLocalAddress());
        $client = $this->getValueFromResolvedPromise($promise);

        $that = $this;
        $server->on('message', function ($message, $remote, $server) use ($that) {
            $that->assertEquals('test', $message);

            // once the server receives a message, send it pack to client and stop server
            $server->send('response:' . $message, $remote);
            $server->end();
        });

        $client->on('message', function ($message, $remote, $client) use ($that) {
            $that->assertEquals('response:test', $message);

            // once the client receives a message, stop client
            $client->end();
        });

        $client->send('test');

        $this->loop->run();
    }
}
