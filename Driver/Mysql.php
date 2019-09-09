<?php
/**
 * 数据库连接类，依赖 PDO_MYSQL 扩展
 * mysql version >= 5.7
 */

namespace Yonna\Database\Driver;

use Yonna\Database\Driver\Mysql\Table;

class Mysql
{

    private $setting = null;
    private $options = null;

    /**
     * 构造方法
     *
     * @param array $setting
     */
    public function __construct(array $setting)
    {
        $this->setting = $setting;
        $this->options = [];
    }

    /**
     * 析构方法
     * @access public
     */
    public function __destruct()
    {
        $this->setting = null;
        $this->options = null;
    }

    /**
     * 当前时间（只能用于insert 和 update）
     * @return array
     */
    public function now(): array
    {
        return ['exp', 'now()'];
    }

    /**
     * 哪个表
     *
     * @param string $table
     * @return Table
     */
    public function table($table)
    {
        $table = str_replace([' as ', ' AS ', ' As ', ' aS ', ' => '], ' ', trim($table));
        $tableEX = explode(' ', $table);
        if (count($tableEX) === 2) {
            $this->options['table'] = $tableEX[1];
            $this->options['table_origin'] = $tableEX[0];
            if (!isset($this->options['alia'])) {
                $this->options['alia'] = array();
            }
            $this->options['alia'][$tableEX[1]] = $tableEX[0];
        } else {
            $this->options['table'] = $table;
            $this->options['table_origin'] = null;
        }
        return (new Table($this->setting, $this->options));
    }

}
