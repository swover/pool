# 连接池

提供基础的连接池功能，只要实现了`ConnectorInterface`接口的类都可使用。

通过连接池类的`__call`魔术方法，安全的实现了获取连接、放回连接的操作，使用者不用担心连接的冗余。

## 安装
```shell
composer require swover/swover
composer update
```

## 说明

```php
/**
 * 连接池构造函数
 *
 * @param array $poolConfig 连接池配置
 * @param ConnectorInterface $connector 连接器实例
 */
public function __construct(array $poolConfig, ConnectorInterface $connector);
```
### 连接池配置
```php
$poolConfig = [
    'minSize' => 1, // 连接池最小连接数
    'maxSize' => 10, // 连接池最大连接数
    'waitTime' => 3, // 获取连接的超时时间
    'idleTime' => 120, // 连接闲置超时时间
    'poolHandler' => '', //连接池类型
    'failCallback' => '', //连接报错时的回调
];
```

#### minSize
连接池的最小连接数，默认1。
- 获取连接时，如果无可用连接（连接池为空）且已用连接数小于最小连接数时，创建连接返回。
- 释放连接时，如果有可用连接（连接池不空）且已用连接数大于最小连接数时，移除连接。

#### maxSize
连接池的最大连接数，限定了连接池的最大容量，默认10。
- 获取连接时，如果无法取出连接，但已用连接总数小于最大连接数，创建连接返回。
- 释放连接时，如果连接池已满，移除连接。

#### waitTime
获取连接的超时时间，阻塞的从连接池获取连接，当阻塞时间已过仍未获取连接时，做下一步处理或抛出异常，默认3秒。

#### idleTime
连接闲置超时时间，即连接放入连接池后可以闲置的时间，默认12秒。

每次连接放入池子时，都会更新最后活跃时间。获取连接时，通过最后活跃时间和闲置时间判断当前连接是否可用，如果已超时，则移除此链接并重新获取。

#### poolHandler
指定连接池的具体类型，可传字符串['channel','normal']、实现了`PoolHandler`抽象类的对象，默认`normal`。
- normal：使用`\SplQueue`类的作为连接池。
- channel：当显式的指定此类型或处于`swoole`协程内时，使用`\Swoole\Coroutine\Channel`作为连接池。
- 自定义对象：可自行使用实现了`PoolHandler`的对象。

#### failCallback
当通过`__call`调用连接的方法时，如果捕获异常，会回调预定义的处理方法，必须是`is_callable`的。

回调函数接收两个参数，当前连接`$connection`，异常对象`\Throwable`。

### getConnection()
从连接池获取一个可用连接。

### releaseConnection($connection)
释放一个连接到连接池。

### __call()
当通过此对象调用连接类的方法时，将在此魔术方法中实现：获取连接、调用方法、释放连接、捕获异常的操作。


## 示例

```php
//连接器 connector
class MySQLConnector implements \Swover\Pool\ConnectorInterface
{
    private $config = [];
    public function __construct(array $config)
    {
        $this->config = $config;
    }
    public function connect()
    {
        return new \YourConnector([
            'database' => $this->config['database'],
            'host' => $this->config['host'],
            'port' => $this->config['port'],
            'username' => $this->config['username'],
            'password' => $this->config['password'],
        ]);
    }
    public function disconnect($connection)
    {
        // TODO: Implement disconnect() method.
    }
    public function reset($connection)
    {
        // TODO: Implement reset() method.
    }
    public function ping($connection)
    {
        // TODO: Implement ping() method.
    }
}

//配置
$config = [
    'database' => 'test',
    'host' => '127.0.0.1',
    'port' => '3306',
    'username' => 'root',
    'password' => 'root',
    'pool_config' => [
        'minSize' => 3,
        'maxSize' => 50,
        'waitTime' => 3,
        'idleTime' => 120,
        'poolHandler' => 'channel',
        'failCallback' => function($connection, $e) {
            echo $e->getMessage();
        }
    ]
];

//构造连接池
$pool = new ConnectionPool($config['pool_config'], new MySQLConnector($config));

// 获取连接
$connection = $pool->getConnection();
// 执行查询
echo $connection->query("SELECT 1;");
// 释放连接
$pool->releaseConnection($connection);

// 通过__call自动获取链接、执行查询、释放连接
$pool->query("SELECT 1;");

```