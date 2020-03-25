<?php

namespace Yonna\Database\Driver\Mdo;

use Yonna\Throwable\Exception;

/**
 * Trait TraitOperat
 * @package Yonna\Database\Driver\Mdo
 */
trait TraitOperat
{

    /**
     * @param int $limit
     * @return Collection
     */
    public function limit(int $limit): self
    {
        $this->options['limit'] = $limit;
        return $this;
    }

    /**
     * @return mixed
     */
    public function multi()
    {
        return $this->query('select');
    }

    /**
     * 查找记录一条
     * @return mixed
     */
    public function one()
    {
        $this->limit(1);
        $result = $this->multi();
        return $result && is_array($result) ? reset($result) : $result;
    }

    /**
     * 统计
     * @param $field
     * @return int
     */
    public function count()
    {
        return $this->query('count');
    }


    /**
     * insert
     * @param $data
     * @return mixed
     */
    public function insert($data)
    {
        $this->data = $data;
        return $this->query('insert');
    }

    /**
     * insert all
     * @param $data
     * @return mixed
     */
    public function insertAll($data)
    {
        $this->data = $data;
        return $this->query('insertAll');
    }

    /**
     * update
     * @param $data
     * @param bool $sure
     * @return mixed
     * @throws Exception\DatabaseException
     */
    public function update($data, $sure = false)
    {
        $where = $this->parseWhere();
        if (!$where && !$sure) {
            Exception::database('Mongo update must be sure when without where');
        }
        $this->data = $data;
        return $this->query('update');
    }

    /**
     * delete
     * @param bool $sure
     * @return mixed
     * @throws Exception\DatabaseException
     */
    public function delete($sure = false)
    {
        $where = $this->parseWhere();
        if (!$where && !$sure) {
            Exception::database('Mongo delete must be sure when without where');
        }
        return $this->query('delete');
    }

}