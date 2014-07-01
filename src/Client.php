<?php

namespace Clue\React\Ami;

use Clue\React\Ami\Protocol\Event;
use Clue\React\Ami\Protocol\Action;
use Evenement\EventEmitter;
use React\Stream\StreamInterface;
use Clue\React\Ami\Protocol\Parser;
use React\Promise\Deferred;
use Exception;
use UnexpectedValueException;
use Clue\React\Ami\Protocol\Message;
use Clue\React\Ami\Protocol\ErrorException;

class Client extends EventEmitter
{
    private $stream;

    private $pending = array();
    private $ending = false;

    public function __construct(StreamInterface $stream, Parser $parser = null)
    {
        if ($parser === null) {
            $parser = new Parser();
        }
        $this->stream = $stream;

        $that = $this;
        $this->stream->on('data', function ($chunk) use ($parser, $that) {
            try {
                $messages = $parser->push($chunk);
            } catch (UnexpectedValueException $e) {
                $that->emit('error', array($e, $that));
                return;
            }

            foreach ($messages as $message) {
                $that->handleMessage($message);
            }
        });

        $this->on('error', array($that, 'close'));

        $this->stream->on('close', array ($that, 'close'));

        $this->stream->resume();
    }

    public function request(Action $message)
    {
        $deferred = new Deferred();

        if ($this->ending) {
            $deferred->reject(new Exception('Already ending'));
        } else {
            $out = $message->getMessageSerialized();
            //var_dump('out', $out);
            $this->stream->write($out);
            $this->pending[$message->getActionId()] = $deferred;
        }

        return $deferred->promise();
    }

    public function handleMessage(Message $message)
    {
        if ($message instanceof Event) {
            $this->emit('event', array($message));
            return;
        }
        $id = $message->getActionId();
        if (!isset($this->pending[$id])) {
            var_dump('unexpected', $message);
            // unexpected message
            return;
        }

        if ($message->getPart('Response') === 'Error') {
            $this->pending[$id]->reject(new ErrorException($message));
        } else {
            $this->pending[$id]->resolve($message);
        }
        unset($this->pending[$id]);

        // last pending messages received => close client
        if ($this->ending && !$this->pending) {
            $this->close();
        }
    }

    public function close()
    {
        if ($this->stream === null) {
            return;
        }

        $this->ending = true;

        $stream = $this->stream;
        $this->stream = null;
        $stream->close();

        $this->emit('close', array($this));

        // reject all remaining/pending requests
        foreach ($this->pending as $deferred) {
            $deferred->reject(new Exception('Client closing'));
        }
        $this->pending = array();
    }

    public function end()
    {
        $this->ending = true;

        if (!$this->isBusy()) {
            $this->close();
        }
    }

    public function isBusy()
    {
        return !!$this->pending;
    }
}