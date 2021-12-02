<?php
declare(strict_types = 1);
/**
 * Created by PhpStorm.
 * User: XYQ
 * Date: 2020-04-10
 * Time: 18:07
 */

namespace xyqWeb\queue\library;


use xyqWeb\queue\drivers\ConfigException;
use xyqWeb\queue\QueueObject;

class Serializer extends QueueObject
{
    /**
     * @var string
     */
    public $classKey = 'class';
    /**
     * @var int
     */
    public $options = 0;


    /**
     * @inheritdoc
     * @throws ConfigException
     */
    public function serialize($job)
    {
        return json_encode($this->toArray($job), $this->options);
    }

    /**
     * @inheritdoc
     */
    public function unSerialize($serialized)
    {
        return $this->fromArray(json_decode($serialized, true));
    }

    /**
     * @param mixed $data
     * @return array|mixed
     * @throws ConfigException
     */
    protected function toArray($data)
    {
        if (is_object($data)) {
            $result = [$this->classKey => get_class($data)];
            foreach (get_object_vars($data) as $property => $value) {
                if ($property === $this->classKey) {
                    throw new ConfigException("Object cannot contain $this->classKey property.");
                }
                $result[$property] = $this->toArray($value);
            }

            return $result;
        }

        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                if ($key === $this->classKey) {
                    throw new ConfigException("Array cannot contain $this->classKey key.");
                }
                $result[$key] = $this->toArray($value);
            }

            return $result;
        }

        return $data;
    }

    /**
     * @param array $data
     * @return mixed
     * @throws ConfigException
     */
    protected function fromArray($data)
    {
        if (!is_array($data)) {
            return $data;
        }

        if (!isset($data[$this->classKey])) {
            $result = [];
            foreach ($data as $key => $value) {
                $result[$key] = $this->fromArray($value);
            }

            return $result;
        }

        $config = ['class' => $data[$this->classKey]];
        unset($data[$this->classKey]);
        foreach ($data as $property => $value) {
            $config[$property] = $this->fromArray($value);
        }
        return self::createObject($config);
    }

    /**
     * 创建对象
     *
     * @author xyq
     * @param $type
     * @return mixed
     * @throws ConfigException
     */
    public static function createObject($type)
    {
        if (is_array($type) && isset($type['class'])) {
            $class = $type['class'];
            unset($type['class']);
            return new $class($type);
        }
        throw new ConfigException('Unsupported configuration type: ' . gettype($type));
    }
}
