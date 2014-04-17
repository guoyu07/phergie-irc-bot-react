<?php
/**
 * Phergie (http://phergie.org)
 *
 * @link http://github.com/phergie/phergie-irc-bot-react for the canonical source repository
 * @copyright Copyright (c) 2008-2014 Phergie Development Team (http://phergie.org)
 * @license http://phergie.org/license New BSD License
 * @package Phergie\Irc\Bot\React
 */

namespace Phergie\Irc\Bot\React;

use Monolog\Logger;
use Phergie\Irc\ConnectionInterface as BaseConnectionInterface;
use Phergie\Irc\Client\React\Client;
use Phergie\Irc\Client\React\ClientInterface;
use Phergie\Irc\Client\React\WriteStream;
use Phergie\Irc\Event\CtcpEvent;
use Phergie\Irc\Event\EventInterface;
use Phergie\Irc\Event\ParserConverter;
use Phergie\Irc\Event\ParserConverterInterface;
use Phergie\Irc\Event\UserEvent;
use Phergie\Irc\Event\ServerEvent;
use Phergie\Irc\Parser;
use Phergie\Irc\ParserInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerAwareInterface;

/**
 * Class for an IRC bot that reads in configuration files, connects to IRC
 * servers, and configures plugins to receive events of interest from those
 * servers.
 *
 * @category Phergie
 * @package Phergie\Irc\Bot\React
 */
class Bot
{
    /**
     * IRC client in use by the bot
     *
     * @var \Phergie\Irc\Client\React\Client
     */
    protected $client;

    /**
     * Configuration in use by the bot
     *
     * @var array
     */
    protected $config = array();

    /**
     * Logger in use by the bot, defaults to logger in use by the IRC client
     *
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * Parser for converting generated IRC commands into event objects
     *
     * @var \Phergie\Irc\ParserInterface
     */
    protected $parser;

    /**
     * Converter for event data from the IRC client's underlying parser
     *
     * @var \Phergie\Irc\Event\ParserConverter
     */
    protected $converter;

    /**
     * Queue for events generated by plugins to be sent to servers
     *
     * @var \Phergie\Irc\Bot\React\EventQueue
     */
    protected $queue;

    /**
     * Sets the IRC client for the bot to use.
     *
     * @param \Phergie\Irc\Client\React\ClientInterface $client
     */
    public function setClient(ClientInterface $client)
    {
        $this->registerClientSubscribers($client);
        $this->client = $client;
    }

    /**
     * Returns the IRC client in use by the bot.
     *
     * @return \Phergie\Irc\Client\React\ClientInterface
     */
    public function getClient()
    {
        if (!$this->client) {
            $this->setClient(new Client);
        }
        return $this->client;
    }

    /**
     * Sets the configuration to be used by the bot in its operations.
     *
     * See config.sample.php for an example configuration file.
     *
     * @param array $config Associative array keyed by setting name
     */
    public function setConfig(array $config)
    {
        $this->config = $config;
    }

    /**
     * Returns the configuration in use by the bot.
     *
     * @return array Associative array keyed by setting name
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * Sets the logger in use by the bot.
     *
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Returns the logger in use by the bot.
     *
     * @return \Psr\Log\LoggerInterface
     */
    public function getLogger()
    {
        if (!$this->logger) {
            $this->logger = $this->getClient()->getLogger();
        }
        return $this->logger;
    }

    /**
     * Sets the parser for generated event data in use by the bot.
     *
     * @param \Phergie\Irc\ParserInterface $parser
     */
    public function setParser(ParserInterface $parser)
    {
        $this->parser = $parser;
    }

    /**
     * Returns the parser for generated event data in use by the bot.
     *
     * @return \Phergie\Irc\ParserInterface
     */
    public function getParser()
    {
        if (!$this->parser) {
            $this->parser = new Parser;
        }
        return $this->parser;
    }

    /**
     * Sets the parser converter for event data in use by the bot.
     *
     * @param \Phergie\Irc\Event\ParserConverterInterface $converter
     */
    public function setConverter(ParserConverterInterface $converter)
    {
        $this->converter = $converter;
    }

    /**
     * Returns the parser converter for event data in use by the bot.
     *
     * @return \Phergie\Irc\Event\ParserConverterInterface
     */
    public function getConverter()
    {
        if (!$this->converter) {
            $this->converter = new ParserConverter;
        }
        return $this->converter;
    }

    /**
     * Sets the event queue for the bot to use.
     *
     * @param \Phergie\Irc\Bot\React\EventQueueInterface $queue
     */
    public function setEventQueue(EventQueueInterface $queue)
    {
        $this->queue = $queue;
    }

    /**
     * Returns the event queue in use by the bot.
     *
     * @return \Phergie\Irc\Bot\React\EventQueueInterface
     */
    public function getEventQueue()
    {
        if (!$this->queue) {
            $this->queue = new EventQueue;
        }
        return $this->queue;
    }

    /**
     * Initiates an event loop for the bot in which it will connect to servers
     * and monitor those connections for events to forward to plugins.
     *
     * @throws \RuntimeException if configuration is inconsistent with
     *         expected structure
     */
    public function run()
    {
        $client = $this->getClient();

        // Register global plugins
        $plugins = $this->getPlugins($this->config);
        $this->registerGlobalPluginSubscribers($client, $plugins);

        // Register connection-specific plugins
        $connections = $this->getConnections($this->config);
        foreach ($connections as $connection) {
            if ($connection instanceof ConnectionInterface) {
                $this->registerConnectionPluginSubscribers($client, $connection);
            }
        }

        $client->run($connections);
    }

    /**
     * Extracts connections from configuration.
     *
     * @param array $config Associative array keyed by setting name
     * @return \Phergie\Irc\ConnectionInterface[]
     */
    protected function getConnections(array $config)
    {
        if (!isset($config['connections'])) {
            throw new \RuntimeException('Configuration must contain a "connections" key');
        }

        if (!is_array($config['connections']) || !$config['connections']) {
            throw new \RuntimeException('Configuration "connections" key must reference a non-empty array');
        }

        $connections = array_filter(
            $config['connections'],
            function($connection) {
                return $connection instanceof BaseConnectionInterface;
            }
        );
        if (count($connections) != count($config['connections'])) {
            throw new \RuntimeException(
                'All configuration "connections" array values must implement \Phergie\Irc\ConnectionInterface'
            );
        }

        $filtered = array_filter(
            $connections,
            function($connection) {
                return $connection instanceof ConnectionInterface;
            }
        );
        foreach ($filtered as $connection) {
            $this->processPlugins($connection->getPlugins());
        }

        return $connections;
    }

    /**
     * Extracts plugins from configuration.
     *
     * @param array $config Associative array keyed by setting name
     * @return \Phergie\Irc\Bot\React\Plugin\PluginInterface[]
     * @throws \RuntimeException if any plugin event callback is invalid
     */
    protected function getPlugins(array $config)
    {
        if (!isset($config['plugins'])) {
            throw new \RuntimeException('Configuration must contain a "plugins" key');
        }

        if (!is_array($config['plugins'])) {
            throw new \RuntimeException('Configuration "plugins" key must reference an array');
        }

        $plugins = array_filter(
            $config['plugins'],
            function($plugin) {
                return $plugin instanceof PluginInterface;
            }
        );
        if (count($plugins) != count($config['plugins'])) {
            throw new \RuntimeException(
                'All configuration "plugins" array values must implement \Phergie\Irc\Bot\React\PluginInterface'
            );
        }

        $this->processPlugins($plugins);

        return $plugins;
    }

    /**
     * Processes a list of plugins for use.
     *
     * @param \Phergie\Irc\Bot\React\Plugin\PluginInterface[]
     */
    protected function processPlugins(array $plugins)
    {
        $client = $this->getClient();
        $logger = $this->getLogger();
        foreach ($plugins as $plugin) {
            $this->validatePluginEvents($plugin);
            if ($plugin instanceof LoggerAwareInterface) {
                $plugin->setLogger($logger);
            }
            if ($plugin instanceof EventEmitterAwareInterface) {
                $plugin->setEventEmitter($client);
            }
        }
    }

    /**
     * Validates a plugin's event callbacks.
     *
     * @param \Phergie\Irc\Bot\React\Plugin\PluginInterface $plugin
     * @throws \RuntimeException if any event callback is invalid
     */
    protected function validatePluginEvents(PluginInterface $plugin)
    {
        $events = $plugin->getSubscribedEvents();
        if (!is_array($events)) {
            throw new \RuntimeException(
                'Plugin of class ' . get_class($plugin) .
                    ' has getSubscribedEvents() implementation' .
                    ' that does not return an array'
            );
        }
        foreach ($events as $event => $method) {
            if (!is_string($event) || !is_callable(array($plugin, $method))) {
                throw new \RuntimeException(
                    'Plugin of class ' . get_class($plugin) .
                        ' returns non-string event name or invalid callback' .
                        ' for event "' . $event . '"'
                );
            }
        }
    }

    /**
     * Configures the client to emit events for specific types of messages.
     *
     * @param \Phergie\Irc\Client\React\Client $client Client for which to
     *        configure events
     */
    protected function registerClientSubscribers(Client $client)
    {
        $bot = $this;

        $client->on('irc.received', function($message, $write, $connection) use ($bot) {
            $bot->processClientEvent('irc.received', $message, $connection, $write);
        });

        $parser = $this->getParser();
        $client->on('irc.sent', function($message, $write, $connection) use ($bot, $parser) {
            $parsed = $parser->parse($message);
            $bot->processClientEvent('irc.sent', $parsed, $connection, $write);
        });
    }

    /**
     * Callback to process client events. Not intended to be called from
     * outside this class.
     *
     * @param string $event Received client event
     * @param array $message Parsed message
     * @param \Phergie\Irc\ConnectionInterface $connection Connection on which
     *        the event occurred
     * @param \Phergie\Irc\Client\React\WriteStream $write Stream used to send
     *        commands to the server
     */
    public function processClientEvent($event, array $message, BaseConnectionInterface $connection, WriteStream $write)
    {
        $converter = $this->getConverter();
        $converted = $converter->convert($message);
        $converted->setConnection($connection);

        $client = $this->getClient();
        $queue = $this->getEventQueue();
        $params = array($converted, $queue);
        $subtype = $this->getEventSubtype($converted);
        $client->emit($event . '.each', $params);
        $client->emit($event . '.' . $subtype, $params);

        $client->emit('irc.sending.all', array($queue));
        while ($extracted = $queue->extract()) {
            $extracted->setConnection($connection);
            $params = array($extracted, $queue);
            $subtype = $this->getEventSubtype($extracted);
            $client->emit('irc.sending.each', $params);
            $client->emit('irc.sending.' . $subtype, $params);

            if ($extracted instanceof CtcpEvent) {
                $method = 'ctcp' . $extracted->getCtcpCommand();
                if ($extracted->getCommand() === 'NOTICE') {
                    $method .= 'Response';
                }
            } else {
                $method = 'irc' . $extracted->getCommand();
            }
            call_user_func_array(
                array($write, $method),
                $extracted->getParams()
            );
        }
    }

    /**
     * Returns an event subtype corresponding to a given event object, used to
     * generate event names when emitting events.
     *
     * @param \Phergie\Irc\Event\EventInterface $event
     * @return string
     */
    protected function getEventSubtype(EventInterface $event)
    {
        $subevent = '';
        if ($event instanceof CtcpEvent) {
            $subevent = 'ctcp.' . strtolower($event->getCtcpCommand());
        } elseif ($event instanceof UserEvent) {
            $subevent = strtolower($event->getCommand());
        } elseif ($event instanceof ServerEvent) {
            $subevent = strtolower($event->getCode());
        }
        return $subevent;
    }

    /**
     * Registers event callbacks from connection-specific plugins.
     *
     * @param \Phergie\Irc\Client\React\Client $client Client with which to
     *        register callbacks
     * @param \Phergie\Irc\Bot\React\PluginInterface[] $plugins Plugins from
     *        which to get callbacks
     */
    protected function registerGlobalPluginSubscribers(Client $client, array $plugins)
    {
        foreach ($plugins as $plugin) {
            $callbacks = $plugin->getSubscribedEvents();
            foreach ($callbacks as $event => $method) {
                $client->on($event, array($plugin, $method));
            }
        }
    }

    /**
     * Registers event callbacks from connection-specific plugins.
     *
     * @param \Phergie\Irc\Client\React\Client $client Client with which to
     *        register callbacks
     * @param \Phergie\Irc\Bot\React\ConnectionInterface $connection Connection where
     *        plugin callbacks will only receive events pertaining to that
     *        connection for events that are connection-specific
     */
    protected function registerConnectionPluginSubscribers(Client $client, ConnectionInterface $connection)
    {
        // Define a callback wrapper used to limit callback invocations to
        // the specific connection
        $wrapper = function($callback) use ($connection) {
            return function(EventInterface $event) use ($callback, $connection) {
                if ($event->getConnection() === $connection) {
                    return call_user_func_array($callback, func_get_args());
                }
            };
        };

        // Register plugin callbacks with the client
        foreach ($connection->getPlugins() as $plugin) {
            $callbacks = $plugin->getSubscribedEvents();
            foreach ($callbacks as $event => $method) {
                $client->on($event, $wrapper(array($plugin, $method)));
            }
        }
    }
}
