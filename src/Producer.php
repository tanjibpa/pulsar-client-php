<?php
/**
 * Created by PhpStorm.
 * User: Sunny
 * Date: 2022/6/29
 * Time: 10:01 PM
 */

namespace Pulsar;

use Google\CRC32\CRC32;
use Protobuf\AbstractMessage;
use Pulsar\Exception\OptionsException;
use Pulsar\Exception\RuntimeException;
use Pulsar\Proto\CommandSendReceipt;
use Pulsar\Proto\KeyValue;
use Pulsar\Proto\MessageMetadata;
use Pulsar\Proto\SingleMessageMetadata;
use Pulsar\Traits\CommandSendBuilder;
use Pulsar\Traits\ProducerKeepAlive;
use Pulsar\Util\Buffer;
use Pulsar\Util\Helper;

/**
 * Class Producer
 *
 * @package Pulsar
 */
class Producer extends Client
{
    use ProducerKeepAlive;
    use CommandSendBuilder;

    /**
     * @var ProducerOptions
     */
    protected $options;


    /**
     * @var array<PartitionProducer>
     */
    protected $producers = [];


    /**
     * @var array<int,array<int,callable>>
     */
    protected $callbacks = [];


    /**
     * @param string $url
     * @param ProducerOptions $options
     * @throws Exception\OptionsException
     */
    public function __construct(string $url, ProducerOptions $options)
    {
        parent::__construct($url, $options);
    }


    /**
     * @return void
     * @throws Exception\IOException
     */
    public function connect()
    {
        parent::initialization();

        // Send CreateProducer Command
        foreach ($this->topicManage->all() as $id => $topic) {
            $io = $this->topicManage->getConnection($topic);
            $this->producers[] = new PartitionProducer($id, $topic, $io, $this->options);
        }
    }




    /**
     * @param mixed $payload
     * @param array $options
     * @return string
     * @throws RuntimeException
     * @throws \Exception
     */
    public function send($payload, array $options = []): string
    {
        // schema payload encode
        if ($schema = $this->options->getSchema()) {
            $payload = $schema->encode($payload);
        }

        $producer = $this->getPartitionProducer();
        $messageOptions = new MessageOptions($options);
        $buffer = $this->buildSendBuffer(
            $producer,
            $payload,
            $messageOptions,
            $messageOptions->getSequenceID()
        );

        /**
         * @var $response Response
         */
        $response = $producer->send($buffer);

        /**
         * @var $receipt CommandSendReceipt
         */
        $receipt = $response->getSubCommand();
        $receipt->getMessageId()->setPartition($producer->getID());
        return Helper::serializeID($receipt->getMessageId());
    }



    /**
     * @param string $payload
     * @param callable $callable
     * @param array $options
     * @return void
     * @throws RuntimeException|OptionsException
     * @throws \Exception
     */
    public function sendAsync(string $payload, callable $callable, array $options = [])
    {
        $messageOptions = new MessageOptions($options);
        $sequenceID = $messageOptions->getSequenceID();

        $producer = $this->getPartitionProducer();
        $buffer = $this->buildSendBuffer($producer, $payload, $messageOptions, $sequenceID);
        $producer->sendAsync($buffer);
        $this->callbacks[ $sequenceID ] = [$producer->getID(), $callable];
    }


    /**
     * @return void
     * @throws Exception\IOException
     * @throws RuntimeException
     */
    public function wait()
    {
        do {

            // It actually takes data from the memory buffer
            $response = $this->eventloop->wait();

            /**
             * @var $receipt CommandSendReceipt
             */
            $receipt = $response->getSubCommand();

            $seqID = $receipt->getSequenceId();

            $callbackData = $this->callbacks[ $seqID ];

            $receipt->getMessageId()->setPartition($callbackData[0]);

            // Execute callback
            call_user_func($callbackData[1], Helper::serializeID($receipt->getMessageId()));

            // Removing
            unset($this->callbacks[ $seqID ]);

        } while (count($this->callbacks));
    }


    /**
     * @return void
     * @throws Exception\IOException
     * @throws \Exception
     * close producer and socket
     */
    public function close()
    {
        foreach ($this->producers as $producer) {
            $producer->close();
        }

        parent::close();

        // set keepalive false notify event loop exit
        $this->keepalive = false;
    }


    /**
     * @return PartitionProducer
     */
    protected function getPartitionProducer(): PartitionProducer
    {
        return $this->producers[ mt_rand(0, count($this->producers) - 1) ];
    }


    /**
     * @param Buffer $buffer
     * @return float|int
     */
    protected function getChecksum(Buffer $buffer)
    {
        $crc = CRC32::create(CRC32::CASTAGNOLI);
        $crc->update($buffer->bytes());
        return hexdec($crc->hash());
    }


    /**
     * @param AbstractMessage $message
     * @param MessageOptions $options
     * @return void
     * @throws OptionsException
     */
    protected function appendProperties(AbstractMessage &$message, MessageOptions $options)
    {
        foreach ($options->getProperties() as $key => $val) {
            $kv = new KeyValue();
            $kv->setKey($key);
            if (is_array($val)) {
                $val = json_encode($val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $kv->setValue($val);

            /**
             * @var $message MessageMetadata|SingleMessageMetadata
             */
            $message->addProperties($kv);
        }
    }
}