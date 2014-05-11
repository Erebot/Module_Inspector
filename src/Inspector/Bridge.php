<?php

/*
* This file is part of pssht.
*
* (c) François Poirotte <clicky@erebot.net>
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/

namespace Clicky\Pssht;

use Clicky\Pssht\Connection;
use Clicky\Pssht\Transport;
use Clicky\Pssht\MessageInterface;
use Clicky\Pssht\Messages\CHANNEL\DATA;

/**
 * Bridge between pssht \& XRL.
 */
class XRL implements \Clicky\Pssht\HandlerInterface
{
    /// SSH connection layer.
    protected $connection;

    /// Internal buffer.
    protected $buffer;

    /// XML-RPC server.
    protected $server;

    /**
     * Construct a new bridge between pssht \& XRL.
     *
     *  \param Transport $transport
     *      SSH transport layer.
     *
     *  \param Connection $connection
     *      SSH connection layer.
     *
     *  \param MessageInterface $message
     *      SSH message being handled.
     */
    public function __construct(
        Transport $transport,
        Connection $connection,
        MessageInterface $message
    ) {
        $this->connection   = $connection;
        $this->buffer       = new \Clicky\Pssht\Buffer();

#        require_once(
#            dirname(dirname(__DIR__)) .
#            DIRECTORY_SEPARATOR . 'XRL' .
#            DIRECTORY_SEPARATOR . 'src' .
#            DIRECTORY_SEPARATOR . 'XRL' .
#            DIRECTORY_SEPARATOR . 'Autoload.php'
#        );
#        \spl_autoload_register(array('XRL_Autoload', 'load'));
        $this->server       = new \XRL_Server();

        $connection->setHandler(
            $message,
            \Clicky\Pssht\Messages\CHANNEL\DATA::getMessageId(),
            $this
        );

        $this->server->foo = function () {
            return 42;
        };
        $this->prompt($transport, $message);

        if ($message instanceof \Clicky\Pssht\Messages\CHANNEL\REQUEST\Exec) {
            $message = new \Clicky\Pssht\Messages\CHANNEL\DATA(
                $message->getChannel(),
                $message->getCommand()
            );
            $context = array();
            $encoder = new \Clicky\Pssht\Wire\Encoder();
            $message->serialize($encoder);
            $this->handle(
                \Clicky\Pssht\Messages\CHANNEL\DATA::getMessageId(),
                new \Clicky\Pssht\Wire\Decoder($encoder->getBuffer()),
                $transport,
                $context
            );
        }
    }

    /**
     * Prompt user for next command.
     *
     *  \param Transport $transport
     *      Transport layer for the SSH connection.
     *
     *  \param MessageInterface $message
     *      Message being handled.
     */
    protected function prompt(
        \Clicky\Pssht\Transport $transport,
        \Clicky\Pssht\MessageInterface $message
    ) {
        // Display prompt on stderr.
        $response = new \Clicky\Pssht\Messages\CHANNEL\EXTENDED\DATA(
            $this->connection->getChannel($message),
            \Clicky\Pssht\Messages\CHANNEL\EXTENDED\DATA::SSH_EXTENDED_DATA_STDERR,
            '>>> '
        );
        $transport->writeMessage($response);
    }

    public function handle(
        $msgType,
        \Clicky\Pssht\Wire\Decoder $decoder,
        \Clicky\Pssht\Transport $transport,
        array &$context
    ) {
        $message = \Clicky\Pssht\Messages\CHANNEL\DATA::unserialize($decoder);
        $this->buffer->push(trim($message->getData()));
        while (true) {
            $call = $this->buffer->get('</methodCall>');
            if ($call === null) {
                break;
            }

            // Display the command's result.
            $xmlResponse    = $this->server->handle($call);
#            $response       = new \Clicky\Pssht\Messages\CHANNEL\DATA(
#                $this->connection->getChannel($message),
#                ((string) $xmlResponse) . PHP_EOL
#            );
#            $response = new \Clicky\Pssht\Messages\CHANNEL\EXTENDED\DATA(
#                $this->connection->getChannel($message),
#                1,
#                ((string) $xmlResponse) . PHP_EOL
#            );
#            $transport->writeMessage($response);

            $transport->writeMessage(
                new \Clicky\Pssht\Messages\IGNORE(
                    'pssht:' . ((string) $xmlResponse)
                )
            );
        }

        if (count($this->buffer) > 4096) {
            $call = $this->buffer->get(0);

            // Display the command's result.
            $xmlResponse    = $this->server->handle($call);
#            $response       = new \Clicky\Pssht\Messages\CHANNEL\DATA(
#                $this->connection->getChannel($message),
#                ((string) $xmlResponse) . PHP_EOL
#            );
#            $transport->writeMessage($response);
            $transport->writeMessage(
                new \Clicky\Pssht\Messages\IGNORE(
                    'pssht:' . ((string) $xmlResponse)
                )
            );
        }

        // Display the prompt again.
        if (count($this->buffer) === 0) {
            $this->prompt($transport, $message);
        }
    }
}
