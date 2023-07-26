# RabbitMQ: Advanced-практики

## Готовим проект

1. Запускаем контейнеры командой `docker-compose up -d`
2. Входим в контейнер командой `docker exec -it php sh`. Дальнейшие команды будем выполнять из контейнера
3. Устанавливаем зависимости командой `composer install`
4. Выполняем миграции комадной `php bin/console doctrine:migrations:migrate`

## Добавляем консьюмер с пропуском heartbeat и логирование сообщений в БД

1. Устанавливаем пакет `php-amqplib/rabbitmq-bundle`
2. В файле `.env` исправляем параметры подключения к RabbitMQ
    ```shell
    RABBITMQ_URL=amqp://user:password@rabbit-mq:5672
    RABBITMQ_VHOST=/
    ```
3. Добавляем класс `App\Consumer\Stream\Input\Message`
    ```php
    <?php
    
    namespace App\Consumer\Stream\Input;
    
    use JsonException;
    
    class Message
    {
        /** @var string[] */
        private array $texts;
    
        /**
         * @throws JsonException
         */
        public static function createFromQueue(string $messageBody): self
        {
            $message = json_decode($messageBody, true, 512, JSON_THROW_ON_ERROR);
            $result = new self();
            $result->texts = $message['texts'];
    
            return $result;
        }
    
        public function getTexts(): array
        {
            return $this->texts;
        }
    }
    ```
4. Добавляем класс `App\Consumer\Stream\Consumer`
    ```php
    <?php
    
    namespace App\Consumer\Stream;
    
    use App\Consumer\Stream\Input\Message;
    use App\Entity\MessageLog;
    use Doctrine\ORM\EntityManagerInterface;
    use JsonException;
    use OldSound\RabbitMqBundle\RabbitMq\ConsumerInterface;
    use PhpAmqpLib\Message\AMQPMessage;
    
    class Consumer implements ConsumerInterface
    {
        public function __construct(private readonly EntityManagerInterface $entityManager)
        {
        }
    
        public function execute(AMQPMessage $msg): int
        {
            try {
                $message = Message::createFromQueue($msg->getBody());
            } catch (JsonException $e) {
                return $this->reject($e->getMessage());
            }
               
            foreach ($message->getTexts() as $text) {
                sleep(1);
                echo $text."\n";
            }
    
            $messageLog = new MessageLog();
            $messageLog->setMessage($msg->getBody());
            $this->entityManager->persist($messageLog);
            $this->entityManager->flush();

            return self::MSG_ACK;
        }
    
        private function reject(string $error): int
        {
            echo "Incorrect message: $error";
    
            return self::MSG_REJECT;
        }
    }
    ```
5. В файле `config/packages/old_sound_rabbit_mq.yaml`
   1. Исправляем секцию `old_sound_rabbit_mq.connections.default`
        ```yaml
        url: '%env(RABBITMQ_URL)%'
        heartbeat: 5
        ```
   2. Добавляем секцию `old_sound_rabbit_mq.consumers`
        ```yaml
        consumers:
            stream:
                connection: default
                exchange_options: {name: 'old_sound_rabbit_mq.stream', type: direct}
                queue_options: {name: 'old_sound_rabbit_mq.consumer.stream'}
                callback: App\Consumer\Stream\Consumer
                qos_options: {prefetch_size: 0, prefetch_count: 1, global: false}
        ```
6. Запускаем консьюмер командой `php bin/console rabbitmq:consumer -m 100 stream`
7. В браузере переходим по ссылке `localhost:15672`, логинимся с логином/паролем `user / password`, на вкладке `Queues`
видим очередь с нашим консьюмером
8. Публикуем сообщение в очередь `stream`
    ```json
    {
      "texts": [
        "text1",
        "text2",
        "text3",
        "text4",
        "text5",
        "text6",
        "text7",
        "text8",
        "text9",
        "text10"
      ]
    }
    ```
9. Видим в консоли вывод консьюмера, и затем он падает с ошибкой. При этом в интерфейсе RabbitMQ сообщение вернулось
обратно в очередь. В БД сообщение записано в таблицу `message_log`
10. Перезапускаем консьюмер командой `php bin/console rabbitmq:consumer -m 100 stream`, видим ту же картину

## Добавляем обработку по частям

1. Добавляем класс `App\Consumer\Part\Input\Message`
    ```php
    <?php
    
    namespace App\Consumer\Part\Input;
    
    use JsonException;
    
    class Message
    {
        private string $text;
    
        private ?string $sourceMessage;
    
        /**
         * @throws JsonException
         */
        public static function createFromQueue(string $messageBody): self
        {
            $message = json_decode($messageBody, true, 512, JSON_THROW_ON_ERROR);
            $result = new self();
            $result->text = $message['text'];
            $result->sourceMessage = $message['sourceMessage'];
    
            return $result;
        }
    
        public function getText(): string
        {
            return $this->text;
        }
    
        public function getSourceMessage(): ?string
        {
            return $this->sourceMessage;
        }
    }
    ```
2. Добавляем класс `App\Consumer\Part\Consumer`
    ```php
    <?php
    
    namespace App\Consumer\Part;
    
    use App\Consumer\Part\Input\Message;
    use App\Entity\MessageLog;
    use Doctrine\ORM\EntityManagerInterface;
    use JsonException;
    use OldSound\RabbitMqBundle\RabbitMq\ConsumerInterface;
    use PhpAmqpLib\Message\AMQPMessage;
    
    class Consumer implements ConsumerInterface
    {
        public function __construct(private readonly EntityManagerInterface $entityManager)
        {
        }
    
        public function execute(AMQPMessage $msg): int
        {
            try {
                $message = Message::createFromQueue($msg->getBody());
            } catch (JsonException $e) {
                return $this->reject($e->getMessage());
            }
    
            sleep(1);
            echo $message->getText()."\n";
    
            $messageLog = new MessageLog();
            $messageLog->setMessage($msg->getBody());
            $this->entityManager->persist($messageLog);
    
            if ($message->getSourceMessage() !== null) {
                $messageLog = new MessageLog();
                $messageLog->setMessage($message->getSourceMessage());
                $this->entityManager->persist($messageLog);
            }
            $this->entityManager->flush();
    
            return self::MSG_ACK;
        }
    
        private function reject(string $error): int
        {
            echo "Incorrect message: $error";
    
            return self::MSG_REJECT;
        }
    }
    ```
3. В файле `config/packages/old_sound_rabbit_mq.yaml`
   1. Добавляем секцию `old_sound_rabbit_mq.producers`
        ```yaml
        producers:
            part:
                connection: default
                exchange_options: {name: 'old_sound_rabbit_mq.part', type: direct} 
        ```
   2. В секцию `old_sound_rabbit_mq.consumer` добавляем конфигурацию нового консьюмера
        ```php
        part:
            connection: default
            exchange_options: {name: 'old_sound_rabbit_mq.part', type: direct}
            queue_options: {name: 'old_sound_rabbit_mq.consumer.part'}
            callback: App\Consumer\Part\Consumer
            qos_options: {prefetch_size: 0, prefetch_count: 1, global: false}
        ```
4. Добавляем класс `App\Consumer\Stream\Output\PartMessage`
    ```php
    <?php
    
    namespace App\Consumer\Stream\Output;
    
    use JsonException;
    
    class PartMessage
    {
        public function __construct(
            private readonly string $text,
            private readonly ?string $sourceMessage,
        ) {
        }
    
        /**
         * @throws JsonException
         */
        public function toAMQPMessage(): string
        {
            return json_encode(['text' => $this->text, 'sourceMessage' => $this->sourceMessage], JSON_THROW_ON_ERROR);
        }
    }
    ```
5. Исправляем класс `App\Consumer\Stream\Consumer`
    ```php
    <?php
    
    namespace App\Consumer\Stream;
    
    use App\Consumer\Stream\Input\Message;
    use App\Consumer\Stream\Output\PartMessage;
    use JsonException;
    use OldSound\RabbitMqBundle\RabbitMq\ConsumerInterface;
    use OldSound\RabbitMqBundle\RabbitMq\ProducerInterface;
    use PhpAmqpLib\Message\AMQPMessage;
    
    class Consumer implements ConsumerInterface
    {
        public function __construct(
            private readonly ProducerInterface $producer,
        )
        {
        }
    
        public function execute(AMQPMessage $msg): int
        {
            try {
                $message = Message::createFromQueue($msg->getBody());
            } catch (JsonException $e) {
                return $this->reject($e->getMessage());
            }
    
            foreach ($message->getTexts() as $index => $text) {
                $partMessage = new PartMessage(
                    $text,
                    $index + 1 === count($message->getTexts()) ? $msg->getBody() : null
                );
                try {
                    $this->producer->publish($partMessage->toAMQPMessage());
                } catch (JsonException $e) {
                    return $this->reject($e->getMessage());
                }
            }
            
            return self::MSG_ACK;
        }
    
        private function reject(string $error): int
        {
            echo "Incorrect message: $error";
    
            return self::MSG_REJECT;
        }
    }
    ```
6. В файле `config/services.yaml` в секцию `services` добавляем новое описание сервиса:
    ```yaml
    App\Consumer\Stream\Consumer:
        arguments:
            $producer: '@old_sound_rabbit_mq.part_producer'
    ```
7. Очищаем очередь через интерфейс RabbitMQ
8. Запускаем консьюмеры командами
    ```shell
    php bin/console rabbitmq:consumer -m 100 stream &
    php bin/console rabbitmq:consumer -m 100 part
    ```
9. Публикуем сообщение в очередь `stream`
    ```json
    {
      "texts": [
        "text1",
        "text2",
        "text3",
        "text4",
        "text5",
        "text6",
        "text7",
        "text8",
        "text9",
        "text10"
      ]
    }
    ```
10. Видим, что сообщения успешно обработались и результат обработки всего пакета записан в БД в верном порядке.

## Добавляем случайность и масштабируем

1. В классе `App\Consumer\Part\Consumer` в методе `execute` заменяем время ожидания случайным:
    ```php
    public function execute(AMQPMessage $msg): int
    {
        try {
            $message = Message::createFromQueue($msg->getBody());
        } catch (JsonException $e) {
            return $this->reject($e->getMessage());
        }

        sleep(random_int(1, 3));
        echo $message->getText()."\n";

        $messageLog = new MessageLog();
        $messageLog->setMessage($msg->getBody());
        $this->entityManager->persist($messageLog);

        if ($message->getSourceMessage() !== null) {
            $messageLog = new MessageLog();
            $messageLog->setMessage($message->getSourceMessage());
            $this->entityManager->persist($messageLog);
        }
        $this->entityManager->flush();

        return self::MSG_ACK;
    }
    ```
2. Останавливаем запущенные консьюмеры
3. Запускаем 3 экземпляра консьюмера, выполняющих частичную обработку командами
    ```shell
    php bin/console rabbitmq:consumer -m 100 stream &
    php bin/console rabbitmq:consumer -m 100 part &
    php bin/console rabbitmq:consumer -m 100 part &
    php bin/console rabbitmq:consumer -m 100 part
    ```
4. Публикуем сообщение в очередь `stream`
    ```json
    {
      "texts": [
        "text1",
        "text2",
        "text3",
        "text4",
        "text5",
        "text6",
        "text7",
        "text8",
        "text9",
        "text10",
        "text11",
        "text12"
      ]
    }
    ```
5. Видим, что результат обработки всего пакета записан в БД раньше, чем обработано последнее сообщение.

### Переделываем на поточную обработку

1. Исправляем класс `App\Consumer\Part\Input\Message`
    ```php
    <?php
    
    namespace App\Consumer\Part\Input;
    
    use JsonException;
    
    class Message
    {
        /**
         * @param string[] $texts
         */
        public function __construct(
            private readonly array $texts,
            private readonly int $index,
            private readonly array $result,
        ) {
        }
    
    
        /**
         * @throws JsonException
         */
        public static function createFromQueue(string $messageBody): self
        {
            $message = json_decode($messageBody, true, 512, JSON_THROW_ON_ERROR);
    
            return new self($message['texts'], $message['index'], $message['result']);
        }
    
        /**
         * @throws JsonException
         */
        public function toAMQPMessage(): string
        {
            return json_encode(
                ['texts' => $this->texts, 'index' => $this->index, 'result' => $this->result],
                JSON_THROW_ON_ERROR,
            );
        }
    
        public function getTexts(): array
        {
            return $this->texts;
        }
    
        public function getIndex(): int
        {
            return $this->index;
        }
    
        public function getResult(): array
        {
            return $this->result;
        }
    }
    ```
2. Исправляем класс `App\Consumer\Part\Consumer`
    ```php
    <?php
    
    namespace App\Consumer\Part;
    
    use App\Consumer\Part\Input\Message;
    use App\Entity\MessageLog;
    use Doctrine\ORM\EntityManagerInterface;
    use JsonException;
    use OldSound\RabbitMqBundle\RabbitMq\ConsumerInterface;
    use OldSound\RabbitMqBundle\RabbitMq\ProducerInterface;
    use PhpAmqpLib\Message\AMQPMessage;
    
    class Consumer implements ConsumerInterface
    {
        public function __construct(
            private readonly EntityManagerInterface $entityManager,
            private readonly ProducerInterface $producer,
        ) {
        }
    
        public function execute(AMQPMessage $msg): int
        {
            try {
                $message = Message::createFromQueue($msg->getBody());
            } catch (JsonException $e) {
                return $this->reject($e->getMessage());
            }
    
            sleep(random_int(1, 3));
            $text = $message->getTexts()[$message->getIndex()];
            echo $text."\n";
    
            $messageLog = new MessageLog();
            $messageLog->setMessage($msg->getBody());
            $this->entityManager->persist($messageLog);
    
            $result = $message->getResult();
            $result['texts'][] = $text;
            $newIndex = $message->getIndex() + 1;
    
            try {
                if ($newIndex === count($message->getTexts())) {
                    $messageLog = new MessageLog();
                    $messageLog->setMessage(json_encode($result, JSON_THROW_ON_ERROR));
                    $this->entityManager->persist($messageLog);
                } else {
                    $this->producer->publish(
                        (new Message($message->getTexts(), $newIndex, $result))->toAMQPMessage(),
                    );
                }
            } catch (JsonException $e) {
                return $this->reject($e->getMessage());
            }
            $this->entityManager->flush();
    
            return self::MSG_ACK;
        }
    
        private function reject(string $error): int
        {
            echo "Incorrect message: $error";
    
            return self::MSG_REJECT;
        }
    }
    ```
3. Исправляем класс `App\Consumer\Stream\Output\PartMessage`
    ```php
    <?php
    
    namespace App\Consumer\Stream\Output;
    
    use JsonException;
    
    class PartMessage
    {
        /**
         * @param string[] $texts
         */
        public function __construct(
            private readonly array $texts,
        ) {
        }
    
        /**
         * @throws JsonException
         */
        public function toAMQPMessage(): string
        {
            return json_encode(
                ['texts' => $this->texts, 'index' => 0, 'result' => ['texts' => []]],
                JSON_THROW_ON_ERROR
            );
        }
    }
    
    ```
4. В классе `App\Consumer\Stream\Consumer` исправляем метод `execute`
    ```php
    public function execute(AMQPMessage $msg): int
    {
        try {
            $message = Message::createFromQueue($msg->getBody());
        } catch (JsonException $e) {
            return $this->reject($e->getMessage());
        }

        try {
            $this->producer->publish((new PartMessage($message->getTexts()))->toAMQPMessage());
        } catch (JsonException $e) {
            return $this->reject($e->getMessage());
        }

        return self::MSG_ACK;
    }
    ```
5. В файле `config/services.yaml` в секцию `services` добавляем новое описание сервиса:
    ```yaml
    App\Consumer\Part\Consumer:
        arguments:
            $producer: '@old_sound_rabbit_mq.part_producer'
    ```
6. Останавливаем запущенные консьюмеры
7. Запускаем 3 экземпляра консьюмера, выполняющих частичную обработку командами
    ```shell
    php bin/console rabbitmq:consumer -m 100 stream &
    php bin/console rabbitmq:consumer -m 100 part &
    php bin/console rabbitmq:consumer -m 100 part &
    php bin/console rabbitmq:consumer -m 100 part
    ```
8. Публикуем сообщение в очередь `stream`
    ```json
    {
      "texts": [
        "text1",
        "text2",
        "text3",
        "text4",
        "text5",
        "text6",
        "text7",
        "text8",
        "text9",
        "text10",
        "text11",
        "text12"
      ]
    }
    ```
9. Видим, что результат обработки всего пакета записан в БД после обработки последнего сообщения, и обработка велась в
один поток.
10. Публикуем 3 сообщения в очередь `stream`
    ```json
    {
      "texts": [
        "text1",
        "text2",
        "text3",
        "text4",
        "text5",
        "text6",
        "text7",
        "text8",
        "text9",
        "text10",
        "text11",
        "text12"
      ]
    }
    ```
    ```json
    {
      "texts": [
        "varchar1",
        "varchar2",
        "varchar3",
        "varchar4",
        "varchar5",
        "varchar6",
        "varchar7",
        "varchar8",
        "varchar9",
        "varchar10",
        "varchar11",
        "varchar12"
      ]
    }
    ```
    ```json
    {
      "texts": [
        "string1",
        "string2",
        "string3",
        "string4",
        "string5",
        "string6",
        "string7",
        "string8",
        "string9",
        "string10",
        "string11",
        "string12"
      ]
    }
    ```
9. Видим, что результат сообщения обрабатываются параллельно.

## Добавляем асинхронную задачу, отправляемую через контроллер

1. Добавляем класс `App\Consumer\Task\Input\Message`
    ```php
    <?php
    
    namespace App\Consumer\Task\Input;
    
    use JsonException;
    
    class Message
    {
        private int $taskId;
    
        /**
         * @throws JsonException
         */
        public static function createFromQueue(string $messageBody): self
        {
            $message = json_decode($messageBody, true, 512, JSON_THROW_ON_ERROR);
            $result = new self();
            $result->taskId = $message['taskId'];
    
            return $result;
        }
    
        public function getTaskId(): int
        {
            return $this->taskId;
        }
    }
    ```
2. Добавляем класс `App\Consumer\Task\Consumer`
    ```php
    <?php
    
    namespace App\Consumer\Task;
    
    use App\Consumer\Task\Input\Message;
    use App\Entity\Task;
    use Doctrine\ORM\EntityManagerInterface;
    use JsonException;
    use OldSound\RabbitMqBundle\RabbitMq\ConsumerInterface;
    use PhpAmqpLib\Message\AMQPMessage;
    
    class Consumer implements ConsumerInterface
    {
        public function __construct(private readonly EntityManagerInterface $entityManager)
        {
        }
    
        public function execute(AMQPMessage $msg): int
        {
            try {
                $message = Message::createFromQueue($msg->getBody());
            } catch (JsonException $e) {
                return $this->reject($e->getMessage());
            }
    
            /** @var Task|null $task */
            $task = $this->entityManager->getRepository(Task::class)->find($message->getTaskId());
            if ($task !== null) {
                $task->setResult((string)random_int(0, PHP_INT_MAX));
                $task->setCompletedAt();
                $this->entityManager->flush();
            }
    
            return self::MSG_ACK;
        }
    
        private function reject(string $error): int
        {
            echo "Incorrect message: $error";
    
            return self::MSG_REJECT;
        }
    }
    ```
3. В файле `config/packages/old_sound_rabbit_mq.yaml`
   1. В секцию `old_sound_rabbit_mq.producers` добавляем конфигурацию нового продюсера
        ```yaml
        task:
            connection: default
            exchange_options: {name: 'old_sound_rabbit_mq.server.task', type: direct} 
        ```
   2. В секцию `old_sound_rabbit_mq.consumer` добавляем конфигурацию нового консьюмера
        ```php
        task:
            connection: default
            exchange_options: {name: 'old_sound_rabbit_mq.task', type: direct}
            queue_options: {name: 'old_sound_rabbit_mq.consumer.task'}
            callback: App\Consumer\Task\Consumer
            qos_options: {prefetch_size: 0, prefetch_count: 1, global: false}
        ``` 
4. Добавляем класс `App\Controller\TaskController`
    ```php
    <?php
    
    namespace App\Controller;
    
    use App\Entity\Task;
    use Doctrine\ORM\EntityManagerInterface;
    use OldSound\RabbitMqBundle\RabbitMq\ProducerInterface;
    use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
    use Symfony\Component\HttpFoundation\JsonResponse;
    use Symfony\Component\HttpFoundation\Response;
    
    class TaskController extends AbstractController
    {
        public function __construct(
            private readonly EntityManagerInterface $entityManager,
            private readonly ProducerInterface $producer,
        ) {
        }
    
        public function addTask(): Response
        {
            $task = new Task();
            $this->entityManager->persist($task);
            $this->entityManager->flush();
            
            $this->producer->publish(json_encode(['taskId' => $task->getId()], JSON_THROW_ON_ERROR));
    
            return new JsonResponse('Task accepted', Response::HTTP_ACCEPTED);
        }
    }
    ```
5. В файле `config/services.yaml` в секцию `services` добавляем новое описание сервиса:
    ```yaml
    App\Controller\TaskController:
        arguments:
            $producer: '@old_sound_rabbit_mq.task_producer'
    ```
6. В файле `config/routes.yaml` добавляем описание нового endpoint'а:
    ```yaml
    task:
        path: /task
        controller: App\Controller\TaskController::addTask
    ```
7. Запускаем консьюмер командой `php bin/console rabbitmq:consumer -m 100 task`
8. В браузере заходим по адресу `localhost:7777/task`, видим сообщение `Task accepted`, в интерфейсе RabbitMQ видим
обработанное сообщение и в БД результат обработки.

## Реализуем RPC

1. В файле `config/packages/old_sound_rabbit_mq.yaml`
   1. В секции `old_sound_rabbit_mq` добавляем новые подсекции
        ```yaml
        rpc_clients:
            task:
                connection: default
                expect_serialized_response: true
        rpc_servers:
            task:
                connection: default
                callback: App\Consumer\Task\Consumer
                queue_options: { name: 'old_sound_rabbit_mq.task' }
                qos_options: { prefetch_size: 0, prefetch_count: 1, global: false }
        ```
   2. Удаляем описания продюсера и консьюмера `task`
2. Добавляем класс `App\Service\TaskService`
    ```php
    <?php
    
    namespace App\Service;
    
    use App\Entity\Task;
    use Exception;
    use OldSound\RabbitMqBundle\RabbitMq\RpcClient;
    use PhpAmqpLib\Exception\AMQPTimeoutException;
    
    class TaskService
    {
        private readonly string $correlationId;
    
        public function __construct(
            private readonly RpcClient $rpcClient,
            private readonly string $rpcServer,
        ) {
            $this->correlationId = 'task_'.crc32(microtime());
        }
    
        /**
         * @throws Exception
         */
        public function call(Task $task): array
        {
            $this->rpcClient->addRequest(
                json_encode(['taskId' => $task->getId()], JSON_THROW_ON_ERROR),
                $this->rpcServer,
                $this->correlationId
            );
    
            try {
                $reply = $this->rpcClient->getReplies();
            } catch (AMQPTimeoutException $e) {
                throw new Exception($e->getMessage());
            }
    
            if (!isset($reply[$this->correlationId])) {
                throw new Exception(
                    "RPC call response does not contain correlation id {$this->correlationId}"
                );
            }
    
            return $reply[$this->correlationId];
        }
    }
    ```
3. Исправляем класс `App\Controller\TaskController`
    ```php
    <?php
    
    namespace App\Controller;
    
    use App\Entity\Task;
    use App\Service\TaskService;
    use Doctrine\ORM\EntityManagerInterface;
    use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
    use Symfony\Component\HttpFoundation\JsonResponse;
    use Symfony\Component\HttpFoundation\Response;
    
    class TaskController extends AbstractController
    {
        public function __construct(
            private readonly EntityManagerInterface $entityManager,
            private readonly TaskService $taskService,
        ) {
        }
    
        public function addTask(): Response
        {
            $task = new Task();
            $this->entityManager->persist($task);
            $this->entityManager->flush();
    
            return new JsonResponse($this->taskService->call($task), Response::HTTP_OK, [], true);
        }
    }
    ```
4. В файле `config/services.yaml` в секции `services`
   1. Удаляем описание сервиса `App\Controller\TaskController`
   2. Добавляем новое описание сервиса:
        ```yaml
        App\Service\TaskService:
            arguments:
                $rpcClient: '@old_sound_rabbit_mq.task_rpc'
                $rpcServer: 'task'
        ```
5. В классе `App\Consumer\Task\Consumer` исправляем метод `execute`
    ```php
    public function execute(AMQPMessage $msg): string
    {
        try {
            $message = Message::createFromQueue($msg->getBody());
        } catch (JsonException $e) {
            return $this->reject($e->getMessage());
        }
   
        sleep(2);

        /** @var Task|null $task */
        $task = $this->entityManager->getRepository(Task::class)->find($message->getTaskId());
        if ($task !== null) {
            $task->setResult((string)random_int(0, PHP_INT_MAX));
            $task->setCompletedAt();
            $this->entityManager->flush();
        }

        return json_encode(
            [
                'result' => $task->getResult(),
                'process_time' => $task->getCompletedAt()?->diff($task->getCreatedAt())->s,
            ],
            JSON_THROW_ON_ERROR,
        );
    }
    ```
6. Останавливаем запущенные консьюмеры
7. Запускаем RPC server командой `php bin/console rabbitmq:rpc-server task`
