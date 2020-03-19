<?php

namespace Yonna\Database\Driver\Pdo;

use Closure;
use Yonna\Database\Driver\AbstractPDO;
use Yonna\Database\Driver\Type;
use Yonna\Throwable\Exception;
use Yonna\Foundation\Moment;

/**
 * Class Where
 * @package Yonna\Database\Driver\Pdo
 */
class Where extends AbstractPDO
{
    use TraitOperat;

    /**
     * where条件对象，实现闭包
     * @var array
     */
    protected $closure = [];

    /**
     * where条件，哪个表
     * @var string
     */
    protected $search_table = '';

    /**
     * where 条件类型设置
     */
    const equalTo = 'equalTo';                              //等于
    const notEqualTo = 'notEqualTo';                        //不等于
    const greaterThan = 'greaterThan';                      //大于
    const greaterThanOrEqualTo = 'greaterThanOrEqualTo';    //大于等于
    const lessThan = 'lessThan';                            //小于
    const lessThanOrEqualTo = 'lessThanOrEqualTo';          //小于等于
    const like = 'like';                                    //包含
    const notLike = 'notLike';                              //不包含
    const isNull = 'isNull';                                //为空
    const isNotNull = 'isNotNull';                          //不为空
    const between = 'between';                              //在值之内
    const notBetween = 'notBetween';                        //在值之外
    const in = 'in';                                        //在或集
    const notIn = 'notIn';                                  //不在或集
    const findInSetOr = 'findInSetOr';                      //findInSetOr (mysql)
    const notFindInSetOr = 'notFindInSetOr';                //notFindInSetOr (mysql)
    const findInSetAnd = 'findInSetAnd';                    //findInSetAnd (mysql)
    const notFindInSetAnd = 'notFindInSetAnd';              //notFindInSetAnd (mysql)
    const any = 'any';                                      //any (pgsql)
    const contains = 'contains';                            //contains (pgsql)
    const isContainsBy = 'isContainsBy';                    //isContainsBy (pgsql)

    /**
     * 构造方法
     *
     * @param array $options
     */
    public function __construct(array $options)
    {
        parent::__construct($options);
    }

    /**
     * 析构方法
     * @access public
     */
    public function __destruct()
    {
        $this->pdoFree();
        parent::__destruct();
    }

    /**
     * 清除所有数据
     */
    protected function resetAll()
    {
        $this->closure = [];
        $this->search_table = '';
        parent::resetAll();
    }

    /**
     * where分析
     * @return string
     * @throws null
     */
    protected function parseWhere()
    {
        return $this->closure ? ' WHERE ' . $this->builtSql($this->closure) : '';
    }

    /**
     * @param $val
     * @param $ft
     * @return array|bool|false|int|string
     * @throws Exception\DatabaseException
     */
    private function parseWhereByFieldType($val, $ft)
    {
        if (!in_array($ft, ['json', 'jsonb']) && is_array($val)) {
            foreach ($val as $k => $v) {
                $val[$k] = $this->parseWhereByFieldType($v, $ft);
            }
            return $val;
        }
        switch ($ft) {
            case 'tinyint':
            case 'smallint':
            case 'int':
            case 'integer':
            case 'bigint':
                $val = intval($val);
                break;
            case 'boolean':
                $val = boolval($val);
                break;
            case 'date':
                $val = date('Y-m-d', strtotime($val));
                break;
            case 'timestamp without time zone':
                $val = Moment::datetimeMicro('Y-m-d H:i:s', $val);
                break;
            case 'timestamp with time zone':
                $val = Moment::datetimeMicro('Y-m-d H:i:s', $val) . substr(date('O', strtotime($val)), 0, 3);
                break;
            case 'smallmoney':
            case 'money':
            case 'numeric':
            case 'decimal':
            case 'float':
            case 'real':
                $val = round($val, 10);
                break;
            case 'char':
            case 'varchar':
            case 'text':
            case 'nchar':
            case 'nvarchar':
            case 'ntext':
                $val = trim($val);
                if ($this->isCrypto()) {
                    $val = $this->Crypto::encrypt($val);
                }
                break;
            default:
                if ($this->options['db_type'] === Type::PGSQL) {
                    if (strpos($ft, 'numeric') !== false) {
                        $val = round($val, 10);
                    }
                }
                break;
        }
        return $val;
    }

    /**
     * @param string $operat see self
     * @param string $field
     * @param null $value
     * @return $this
     */
    private function where($operat, $field, $value = null)
    {
        if ($operat == self::isNull || $operat == self::isNotNull || $value !== null) {//排除空值
            if ($operat != self::like || $operat != self::notLike || ($value != '%' && $value != '%%')) {//排除空like
                $this->closure[] = array(
                    'operat' => $operat,
                    'table' => $this->search_table,
                    'field' => $field,
                    'value' => $value,
                );
            }
        }
        return $this;
    }

    /**
     * 构建where的SQL语句
     * @param $closure
     * @param string $sql
     * @param string $cond
     * @return string|null
     * @throws Exception\DatabaseException
     */
    private function builtSql($closure, $sql = '', $cond = 'and')
    {
        foreach ($closure as $v) {
            $table = isset($v['table']) && $v['table'] ? $v['table'] : $this->getTable();
            if (!$table) {
                return null;
            }
            $ft = $this->getFieldType($table);
            if ($v['operat'] === 'closure') {
                $innerSql = '(' . $this->builtSql($v['closure'], '', $v['cond']) . ')';
                $sql .= $sql ? " {$cond}{$innerSql} " : $innerSql;
            } else {
                $si = strpos($v['field'], '#>>');
                if ($si > 0) {
                    preg_match("/\(?(.*)#>>/", $v['field'], $siField);
                    $ft_type = $ft[$table . '_' . $siField[1]] ?? null;
                } else {
                    $ft_type = $ft[$table . '_' . $v['field']] ?? null;
                }
                if (empty($ft_type)) { // 根据表字段过滤无效field
                    continue;
                }
                if ($this->sqlFilter($v['value'])) {
                    $innerSql = ' ';
                    $field = $this->parseKey($v['field']);
                    if ($si > 0 && strpos($v['field'], '(') === 0) {
                        $innerSql .= '(' . $this->parseKey($table) . '.';
                        $innerSql .= substr($field, 1, strlen($field));
                    } else {
                        $innerSql .= $this->parseKey($table) . '.';
                        $innerSql .= $field;
                    }
                    $isContinue = false;
                    switch ($v['operat']) {
                        case self::equalTo:
                            $value = $this->parseWhereByFieldType($v['value'], $ft_type);
                            $value = $this->parseValue($value);
                            $innerSql .= " = {$value}";
                            break;
                        case self::notEqualTo:
                            $value = $this->parseWhereByFieldType($v['value'], $ft_type);
                            $value = $this->parseValue($value);
                            $innerSql .= " <> {$value}";
                            break;
                        case self::greaterThan:
                            $value = $this->parseWhereByFieldType($v['value'], $ft_type);
                            $value = $this->parseValue($value);
                            $innerSql .= " > {$value}";
                            break;
                        case self::greaterThanOrEqualTo:
                            $value = $this->parseWhereByFieldType($v['value'], $ft_type);
                            $value = $this->parseValue($value);
                            $innerSql .= " >= {$value}";
                            break;
                        case self::lessThan:
                            $value = $this->parseWhereByFieldType($v['value'], $ft_type);
                            $value = $this->parseValue($value);
                            $innerSql .= " < {$value}";
                            break;
                        case self::lessThanOrEqualTo:
                            $value = $this->parseWhereByFieldType($v['value'], $ft_type);
                            $value = $this->parseValue($value);
                            $innerSql .= " <= {$value}";
                            break;
                        case self::like:
                            if ($this->isCrypto()) {
                                $likeO = '';
                                $likeE = '';
                                $vspllit = str_split($v['value']);
                                if ($vspllit[0] === '%') {
                                    $likeO = array_shift($vspllit);
                                }
                                if ($vspllit[count($vspllit) - 1] === '%') {
                                    $likeE = array_pop($vspllit);
                                }
                                $value = $this->parseWhereByFieldType(implode('', $vspllit), $ft_type);
                                $value = $likeO . $value . $likeE;
                            } else {
                                $value = $this->parseWhereByFieldType($v['value'], $ft_type);
                            }
                            $value = $this->parseValue($value);
                            $innerSql .= " like {$value}";
                            break;
                        case self::notLike:
                            if (substr($ft_type, -2) === '[]') {
                                $innerSql = "array_to_string({$innerSql},'')";
                            }
                            if ($this->isCrypto()) {
                                $likeO = '';
                                $likeE = '';
                                $vspllit = str_split($v['value']);
                                if ($vspllit[0] === '%') {
                                    $likeO = array_shift($vspllit);
                                }
                                if ($vspllit[count($vspllit) - 1] === '%') {
                                    $likeE = array_pop($vspllit);
                                }
                                $value = $this->parseWhereByFieldType(implode('', $vspllit), $ft_type);
                                $value = $likeO . $value . $likeE;
                            } else {
                                $value = $this->parseWhereByFieldType($v['value'], $ft_type);
                            }
                            $value = $this->parseValue($value);
                            $innerSql .= " not like {$value}";
                            break;
                        case self::isNull:
                            $innerSql .= " is null ";
                            break;
                        case self::isNotNull:
                            $innerSql .= " is not null ";
                            break;
                        case self::between:
                            $value = $this->parseWhereByFieldType($v['value'], $ft_type);
                            $value = $this->parseValue($value);
                            $innerSql .= " between {$value[0]} and {$value[1]}";
                            break;
                        case self::notBetween:
                            $value = $this->parseWhereByFieldType($v['value'], $ft_type);
                            $value = $this->parseValue($value);
                            $innerSql .= " not between {$value[0]} and {$value[1]}";
                            break;
                        case self::in:
                            $value = $this->parseWhereByFieldType($v['value'], $ft_type);
                            $value = $this->parseValue($value);
                            $value = implode(',', (array)$value);
                            $innerSql .= " in ({$value})";
                            break;
                        case self::notIn:
                            $value = $this->parseWhereByFieldType($v['value'], $ft_type);
                            $value = $this->parseValue($value);
                            $value = implode(',', (array)$value);
                            $innerSql .= " not in ({$value})";
                            break;
                        case self::findInSetOr:
                            if ($this->options['db_type'] !== Type::MYSQL) {
                                Exception::database("{$v['operat']} not support {$this->options['db_type']}");
                            }
                            if ($v['value']) {
                                $v['value'] = (array)$v['value'];
                                foreach ($v['value'] as $vfisk => $vfis) {
                                    if ($vfis) {
                                        $vfis = $this->parseWhereByFieldType($vfis, $ft_type);
                                        $vfis = $this->parseValue($vfis);
                                        if ($vfisk === 0) {
                                            $innerSql = " (find_in_set({$vfis},{$field})";
                                        } else {
                                            $innerSql .= " or find_in_set({$vfis},{$field})";
                                        }
                                    }
                                }
                                $innerSql .= ")";
                            } else {
                                $isContinue = true;
                            }
                            break;
                        case self::notFindInSetOr:
                            $this->askType(Type::MYSQL, $v['operat']);
                            if ($v['value']) {
                                $v['value'] = (array)$v['value'];
                                foreach ($v['value'] as $vfisk => $vfis) {
                                    if ($vfis) {
                                        $vfis = $this->parseWhereByFieldType($vfis, $ft_type);
                                        $vfis = $this->parseValue($vfis);
                                        if ($vfisk === 0) {
                                            $innerSql = " (not find_in_set({$vfis},{$field})";
                                        } else {
                                            $innerSql .= " or not find_in_set({$vfis},{$field})";
                                        }
                                    }
                                }
                                $innerSql .= ")";
                            } else {
                                $isContinue = true;
                            }
                            break;
                        case self::findInSetAnd:
                            $this->askType(Type::MYSQL, $v['operat']);
                            if ($v['value']) {
                                $v['value'] = (array)$v['value'];
                                foreach ($v['value'] as $vfisk => $vfis) {
                                    if ($vfis) {
                                        $vfis = $this->parseWhereByFieldType($vfis, $ft_type);
                                        $vfis = $this->parseValue($vfis);
                                        if ($vfisk === 0) {
                                            $innerSql = " (find_in_set({$vfis},{$field})";
                                        } else {
                                            $innerSql .= " and find_in_set({$vfis},{$field})";
                                        }
                                    }
                                }
                                $innerSql .= ")";
                            } else {
                                $isContinue = true;
                            }
                            break;
                        case self::notFindInSetAnd:
                            $this->askType(Type::MYSQL, $v['operat']);
                            if ($v['value']) {
                                $v['value'] = (array)$v['value'];
                                foreach ($v['value'] as $vfisk => $vfis) {
                                    if ($vfis) {
                                        $vfis = $this->parseWhereByFieldType($vfis, $ft_type);
                                        $vfis = $this->parseValue($vfis);
                                        if ($vfisk === 0) {
                                            $innerSql = " (not find_in_set({$vfis},{$field})";
                                        } else {
                                            $innerSql .= " and not find_in_set({$vfis},{$field})";
                                        }
                                    }
                                }
                                $innerSql .= ")";
                            } else {
                                $isContinue = true;
                            }
                            break;
                        case self::any:
                            $this->askType(Type::PGSQL, $v['operat']);
                            $value = $this->parseWhereByFieldType($v['value'], $ft_type);
                            $value = $this->parseValue($value);
                            $value = (array)$value;
                            array_walk($value, function (&$value) {
                                $value = "({$value})";
                            });
                            $value = implode(',', $value);
                            $innerSql .= " = any (values {$value})";
                            break;
                        case self::contains:
                            $this->askType(Type::PGSQL, $v['operat']);
                            $value = $this->parseWhereByFieldType($v['value'], $ft_type);
                            $value = $this->toPGArray((array)$value, str_replace('[]', '', $ft_type));
                            $value = $this->parseValue($value);
                            $innerSql .= " @> {$value}";
                            break;
                        case self::isContainsBy:
                            $this->askType(Type::PGSQL, $v['operat']);
                            $value = $this->parseWhereByFieldType($v['value'], $ft_type);
                            $value = $this->toPGArray((array)$value, str_replace('[]', '', $ft_type));
                            $value = $this->parseValue($value);
                            $innerSql .= " <@ {$value}";
                            break;
                        default:
                            $isContinue = true;
                            break;
                    }
                    if ($isContinue) continue;
                    $sql .= $sql ? " {$cond}{$innerSql} " : $innerSql;
                }
            }
        }
        return $sql;
    }

    /**
     * 清理where条件
     * @return $this
     */
    public function clearWhere()
    {
        $this->closure = [];
        $this->search_table = '';
        return $this;
    }

    /**
     * 锁定为哪一个表的搜索目标
     * @param $table
     * @return $this
     */
    public function searchTable($table)
    {
        $this->search_table = $table;
        return $this;
    }

    /**
     * @param $field
     * @param $value
     * @return $this
     */
    public function equalTo($field, $value)
    {
        return $this->where(self::equalTo, $field, $value);
    }

    /**
     * @param $field
     * @param $value
     * @return $this
     */
    public function notEqualTo($field, $value)
    {
        return $this->where(self::notEqualTo, $field, $value);
    }

    /**
     * @param $field
     * @param $value
     * @return $this
     */
    public function greaterThan($field, $value)
    {
        return $this->where(self::greaterThan, $field, $value);
    }

    /**
     * @param $field
     * @param $value
     * @return $this
     */
    public function greaterThanOrEqualTo($field, $value)
    {
        return $this->where(self::greaterThanOrEqualTo, $field, $value);
    }

    /**
     * @param $field
     * @param $value
     * @return $this
     */
    public function lessThan($field, $value)
    {
        return $this->where(self::lessThan, $field, $value);
    }

    /**
     * @param $field
     * @param $value
     * @return $this
     */
    public function lessThanOrEqualTo($field, $value)
    {
        return $this->where(self::lessThanOrEqualTo, $field, $value);
    }

    /**
     * @param $field
     * @param $value
     * @return $this
     */
    public function like($field, $value)
    {
        return $this->where(self::like, $field, $value);
    }

    /**
     * @param $field
     * @param $value
     * @return $this
     */
    public function notLike($field, $value)
    {
        return $this->where(self::notLike, $field, $value);
    }

    /**
     * @param $field
     * @return $this
     */
    public function isNull($field)
    {
        return $this->where(self::isNull, $field);
    }

    /**
     * @param $field
     * @return $this
     */
    public function isNotNull($field)
    {
        return $this->where(self::isNotNull, $field);
    }

    /**
     * @param $field
     * @param $value
     * @return $this
     */
    public function between($field, $value)
    {
        if (is_string($value)) $value = explode(',', $value);
        if (!is_array($value)) $value = (array)$value;
        if (count($value) !== 2) return $this;
        if (!$value[0]) return $this;
        if (!$value[1]) return $this;
        return $this->where(self::between, $field, $value);
    }

    /**
     * @param $field
     * @param $value
     * @return $this
     */
    public function notBetween($field, $value)
    {
        if (is_string($value)) $value = explode(',', $value);
        if (!is_array($value)) $value = (array)$value;
        if (count($value) !== 2) return $this;
        return $this->where(self::notBetween, $field, $value);
    }

    /**
     * @param $field
     * @param $value
     * @return $this
     */
    public function in($field, $value)
    {
        return $this->where(self::in, $field, $value);
    }

    /**
     * @param $field
     * @param $value
     * @return $this
     */
    public function notIn($field, $value)
    {
        return $this->where(self::notIn, $field, $value);
    }

    /**
     * @param $field
     * @param $value
     * @return $this
     */
    public function findInSetOr($field, $value)
    {
        return $this->where(self::findInSetOr, $field, $value);
    }

    /**
     * @param $field
     * @param $value
     * @return $this
     */
    public function notFindInSetOr($field, $value)
    {
        return $this->where(self::notFindInSetOr, $field, $value);
    }

    /**
     * @param $field
     * @param $value
     * @return $this
     */
    public function findInSetAnd($field, $value)
    {
        return $this->where(self::findInSetAnd, $field, $value);
    }

    /**
     * @param $field
     * @param $value
     * @return $this
     */
    public function notFindInSetAnd($field, $value)
    {
        return $this->where(self::notFindInSetAnd, $field, $value);
    }

    /**
     * @param array $set
     * @param array $data
     * @return $this
     */
    public function complex(array $set, array $data)
    {
        foreach ($set as $target => $actions) {
            $this->searchTable($target);
            foreach ($actions as $action) {
                foreach ($set as $field) {
                    if (!isset($whereData[$field]) || $data[$field] === null) {
                        continue;
                    }
                    if ($data[$field] !== null) {
                        switch ($action) {
                            case 'like':
                                $this->$action('%' . $data[$field] . '%');
                                break;
                            default:
                                $this->$action($data[$field]);
                                break;
                        }
                        $this->$action($data[$field]);
                    }
                }
            }
        }
        return $this;
    }

    /**
     * 条件and闭包
     * @param Closure $cells
     * @return $this
     */
    public function and(Closure $cells)
    {
        $cells();
        $this->closure = array(array('operat' => 'closure', 'cond' => 'and', 'closure' => $this->closure));
        print_r($this->closure);
        return $this;
    }

    /**
     * 条件or闭包
     * @param Closure $cells
     * @return $this
     */
    public function or(Closure $cells)
    {
        $cells();
        $this->closure = array(array('operat' => 'closure', 'cond' => 'or', 'closure' => $this->closure));
        return $this;
    }

}