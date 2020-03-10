<?php

namespace Illuminate\Database;

use PDO;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Database\Connectors\ConnectionFactory;

/**
 * @mixin \Illuminate\Database\Connection
 */
class DatabaseManager implements ConnectionResolverInterface
{
    /**
     * The application instance.
     *
     * @var \Illuminate\Contracts\Foundation\Application
     */
    protected $app;

    /**
     * The database connection factory instance.
     *
     * @var \Illuminate\Database\Connectors\ConnectionFactory
     */
    protected $factory;

    /**
     * The active connection instances.
     * 用于存放已生成的数据库连接实例
     * @var array
     */
    protected $connections = [];

    /**
     * The custom connection resolvers.
     *
     * @var array
     */
    protected $extensions = [];

    /**
     * The callback to be executed to reconnect to a database.
     *
     * @var callable
     */
    protected $reconnector;

    /**
     * Create a new database manager instance.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @param  \Illuminate\Database\Connectors\ConnectionFactory  $factory
     * @return void
     */
    public function __construct($app, ConnectionFactory $factory)
    {
        $this->app = $app;
        // 初始化数据库驱动连接工程
        $this->factory = $factory;

        $this->reconnector = function ($connection) {
            $this->reconnect($connection->getName());
        };
    }

    /**
     * Get a database connection instance.
     * 获取一个数据库连接的实例
     * @param  string|null  $name
     * @return \Illuminate\Database\Connection
     */
    public function connection($name = null)
    {
        // 从配置中获取数据库名称与类型
        [$database, $type] = $this->parseConnectionName($name);

        $name = $name ?: $database;

        // If we haven't created this connection, we'll create it based on the config
        // provided in the application. Once we've created the connections we will
        // set the "fetch mode" for PDO which determines the query return types.
        if (! isset($this->connections[$name])) {
            $this->connections[$name] = $this->configure(
                // 创建数据库连接
                $this->makeConnection($database), $type
            );
        }

        // 返回连接实例
        return $this->connections[$name];
    }

    /**
     * Parse the connection into an array of the name and read / write type.
     * 将连接的名称和读/写类型进行解析得到数据, 此时$name = null
     * @param  string  $name
     * @return array
     */
    protected function parseConnectionName($name)
    {
        $name = $name ?: $this->getDefaultConnection();

        return Str::endsWith($name, ['::read', '::write'])
                            ? explode('::', $name, 2) : [$name, null];
    }

    /**
     * Make the database connection instance.
     * 创建数据库连接, 此时$name = 'mysql'
     * @param  string  $name
     * @return \Illuminate\Database\Connection
     */
    protected function makeConnection($name)
    {
        // 获取该名称数据库的配置
        $config = $this->configuration($name);

        // First we will check by the connection name to see if an extension has been
        // registered specifically for that connection. If it has we will call the
        // Closure and pass it the config allowing it to resolve the connection.
        if (isset($this->extensions[$name])) {
            return call_user_func($this->extensions[$name], $config, $name);
        }

        // Next we will check to see if an extension has been registered for a driver
        // and will call the Closure if so, which allows us to have a more generic
        // resolver for the drivers themselves which applies to all connections.
        if (isset($this->extensions[$driver = $config['driver']])) {
            return call_user_func($this->extensions[$driver], $config, $name);
        }
        // 通过数据库连接工厂根据不同的配置调用不同的数据库驱动扩展
        return $this->factory->make($config, $name);
    }

    /**
     * Get the configuration for a connection.
     *
     * @param  string  $name
     * @return array
     *
     * @throws \InvalidArgumentException
     */
    protected function configuration($name)
    {
        $name = $name ?: $this->getDefaultConnection();

        // To get the database connection configuration, we will just pull each of the
        // connection configurations and get the configurations for the given name.
        // If the configuration doesn't exist, we'll throw an exception and bail.
        $connections = $this->app['config']['database.connections'];

        if (is_null($config = Arr::get($connections, $name))) {
            // 找不到配置则抛出异常
            throw new InvalidArgumentException("Database [{$name}] not configured.");
        }

        // 解析配置, 因为配置信息可以是mysql、postgre等
        return (new ConfigurationUrlParser)
                    ->parseConfiguration($config);
    }

    /**
     * Prepare the database connection instance.
     *
     * @param  \Illuminate\Database\Connection  $connection
     * @param  string  $type
     * @return \Illuminate\Database\Connection
     */
    protected function configure(Connection $connection, $type)
    {
        $connection = $this->setPdoForType($connection, $type);

        // First we'll set the fetch mode and a few other dependencies of the database
        // connection. This method basically just configures and prepares it to get
        // used by the application. Once we're finished we'll return it back out.
        if ($this->app->bound('events')) {
            $connection->setEventDispatcher($this->app['events']);
        }

        // Here we'll set a reconnector callback. This reconnector can be any callable
        // so we will set a Closure to reconnect from this manager with the name of
        // the connection, which will allow us to reconnect from the connections.
        // 设置一个重新连接器
        $connection->setReconnector($this->reconnector);

        return $connection;
    }

    /**
     * Prepare the read / write mode for database connection instance.
     *
     * @param  \Illuminate\Database\Connection  $connection
     * @param  string|null  $type
     * @return \Illuminate\Database\Connection
     */
    protected function setPdoForType(Connection $connection, $type = null)
    {
        if ($type === 'read') {
            $connection->setPdo($connection->getReadPdo());
        } elseif ($type === 'write') {
            $connection->setReadPdo($connection->getPdo());
        }

        return $connection;
    }

    /**
     * Disconnect from the given database and remove from local cache.
     *
     * @param  string|null  $name
     * @return void
     */
    public function purge($name = null)
    {
        $name = $name ?: $this->getDefaultConnection();

        $this->disconnect($name);

        unset($this->connections[$name]);
    }

    /**
     * Disconnect from the given database.
     *
     * @param  string|null  $name
     * @return void
     */
    public function disconnect($name = null)
    {
        if (isset($this->connections[$name = $name ?: $this->getDefaultConnection()])) {
            $this->connections[$name]->disconnect();
        }
    }

    /**
     * Reconnect to the given database.
     *
     * @param  string|null  $name
     * @return \Illuminate\Database\Connection
     */
    public function reconnect($name = null)
    {
        $this->disconnect($name = $name ?: $this->getDefaultConnection());

        if (! isset($this->connections[$name])) {
            return $this->connection($name);
        }

        return $this->refreshPdoConnections($name);
    }

    /**
     * Refresh the PDO connections on a given connection.
     *
     * @param  string  $name
     * @return \Illuminate\Database\Connection
     */
    protected function refreshPdoConnections($name)
    {
        $fresh = $this->makeConnection($name);

        return $this->connections[$name]
                                ->setPdo($fresh->getPdo())
                                ->setReadPdo($fresh->getReadPdo());
    }

    /**
     * Get the default connection name.
     * 获取默认的数据库连接名称
     * @return string
     */
    public function getDefaultConnection()
    {
        // 默认是mysql, 对应database.connections下的对象名称
        return $this->app['config']['database.default'];
    }

    /**
     * Set the default connection name.
     *
     * @param  string  $name
     * @return void
     */
    public function setDefaultConnection($name)
    {
        $this->app['config']['database.default'] = $name;
    }

    /**
     * Get all of the support drivers.
     *
     * @return array
     */
    public function supportedDrivers()
    {
        return ['mysql', 'pgsql', 'sqlite', 'sqlsrv'];
    }

    /**
     * Get all of the drivers that are actually available.
     *
     * @return array
     */
    public function availableDrivers()
    {
        return array_intersect(
            $this->supportedDrivers(),
            str_replace('dblib', 'sqlsrv', PDO::getAvailableDrivers())
        );
    }

    /**
     * Register an extension connection resolver.
     *
     * @param  string    $name
     * @param  callable  $resolver
     * @return void
     */
    public function extend($name, callable $resolver)
    {
        $this->extensions[$name] = $resolver;
    }

    /**
     * Return all of the created connections.
     *
     * @return array
     */
    public function getConnections()
    {
        return $this->connections;
    }

    /**
     * Set the database reconnector callback.
     *
     * @param  callable  $reconnector
     * @return void
     */
    public function setReconnector(callable $reconnector)
    {
        $this->reconnector = $reconnector;
    }

    /**
     * Dynamically pass methods to the default connection.
     * 动态地将方法传递给默认连接, 如User::table() 此时的$method='table'
     * @param  string  $method
     * @param  array   $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        // 魔术方法, 当类中不存在方法则调用
        // 动态地将方法传递给默认连接, 
        // 如DB::table('users') 则此时为$this->connection()->table('users')
        return $this->connection()->$method(...$parameters);
    }
}
