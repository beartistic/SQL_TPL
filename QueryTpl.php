<?php

namespace Parser;

/**
 * @author zhuwanxiong-xy
 * 功能描述：解析SQL模版,根据上传的字段值创建可运行的SQL语句
 * 使用说明：客户端程序通过指定query(模版)和params(模版参数)运行run方法可获取解析过后的SQL，运行getRenderFields方法获取前端要渲染的条件字段
 */

final class QueryTpl {
    
    private $query;
    private $header;
    private $params = array();
    private $select = array();
    private $where  = array();
    private $group  = array();
    private $having = array();
    private $order  = array();
    private $limit  = array();
    private $constWhere     = array();
    private $inputMapGroup  = array();
    private $mapFields      = array();
    
    public function __construct($query, $params=array())
    {
        $this->query  = $query;
        $this->params = $params;
    }
    
    public function getParams() { return $this->params; }
    public function setParams($params) {$this->params = $params; return $this;}
    public function getQuery() { return $this->query; }
    public function setQuery($query) { $this->query = $query; return $this;}
    public function getHeader() {return $this->header;}

    public function getRenderFields()
    {
        $this->getParsedArea();
        $this->getParsedRenderFields();
        $this->parseWhereVarFieldsWithEscapeChar(':');
        $this->getRenderWhereFieldsExplainAndType();
        $this->getTransformedParams();
        krsort($this->params['where']['fields']);
        return $this->params;
    }
    
    public function run()
    {
        $this->getParsedArea();
        $this->getParsedRenderFields();
        $this->group  = array_pop($this->group);
        $this->having = array_pop($this->having);
        $this->order  = array_pop($this->order);
        $this->limit  = array_pop($this->limit);
        //print_r($this->params);
        
        $this->usePostedValues2FillWhereFields();
        //print_r($this->where);
        $this->fillWhereFieldsWithSharedValue();
        $this->trimFieldsSuffix($this->where);
        
        //print_r($this->constWhere);
        $this->createWhereConditionsWithOperatorAndCombineConstWhere();
        //print_r($this->where);
        
        $this->replaceWhereArea();
        //print_r($this->query);
        
        $this->replaceGroupArea();
        //print_r($this->query);
        
        //$this->replaceOrderArea();
        //print_r($this->query);
        
        $this->replaceLimitArea();
        //print_r($this->query);
        
        $this->replaceSelectArea();
        //print_r($this->query);
        
        return $this->query;
        
    }
    
    
    public function getParsedArea()
    {
        /*
         * 根据各域的正则匹配式解析域中的内容
         */
        $r_select = '/.*?<{\s*select\s+(.*?)\s*}>.*?/is';
        $this->select = $this->parseArea($r_select);
        $r_group = '/.*?<{\s*group\s+by\s+(.*?)\s*}>.*?/is';
        $this->group = $this->parseArea($r_group);
        $r_where = '/.*?<{\s*where\s+(.*?)\s*}>.*?/is';
        $this->where = $this->parseArea($r_where);
        $r_having = '/.*?<{\s*having\s+(.*?)\s*}>.*?/is';
        $this->having = $this->parseArea($r_having);
        $r_order = '/.*?<{\s*order\s+by\s+(.*?)\s*}>.*?/is';
        $this->order = $this->parseArea($r_order);
        $r_limit = '/.*?<{\s*limit\s+(.*?)\s*}>.*?/is';
        $this->limit = $this->parseArea($r_limit);
    }
    
    public function getParsedRenderFields()
    {
        /*
         * 解析各域内容的字段和where域字段解释
         */
        $this->group = $this->parseFieldsWithTrim($this->group);
        $this->where = $this->parseFieldsWithTrim($this->where);
        $this->having = $this->parseFieldsWithTrim($this->having);
        $this->order = $this->parseFieldsWithTrim($this->order);
        $this->limit = $this->parseFieldsWithTrim($this->limit);
        $this->parseWhereVariableFields();
        $this->parseWhereVarFieldsExplain();
    }
    
    private function parseArea($pattern)
    {
        preg_match_all($pattern, $this->query, $matches);
        return isset($matches[0]) ? $matches[1] : array();
    }
    
    private function parseFieldsWithTrim($value)
    {
        $result = array();
        foreach ($value as $v) {
            $v = explode(',', $v);
            array_walk($v, function (&$v) {
                $v = trim($v);
            });
            $result[] = $v;
        }
        return $result;
    }
    
    private function parseWhereVariableFields()
    {
        /* 解析where域变量(3种)和常量
         * 变量：br,md.1{机型}/md{机型},model:where.md.1
         * 常量：ch not regexp '^2',at=1
         */
        foreach ($this->where as $k=> $v) {
            $varWhere = $conWhere = array();
            foreach ($v as $key=> $value) {
                if (preg_match('/\s*\w+\.\d+\{.*\}\s*/', $value) ||
                    preg_match('/\s*\w+\{.*\}\s*/', $value) ||
                    preg_match('/^\w+$/', $value) ||
                    preg_match('/\s*\w+\s*\:.*/', $value)) {
                    $varWhere[$key] = $value;
                } else {
                    $conWhere[$key] = $value;
                }
            }
            $this->where[$k] = $varWhere;
            $this->constWhere[$k] = $conWhere;
        }
    }
    
    
    private function parseWhereVarFieldsWithEscapeChar($char=':')
    {
        foreach ($this->where as $k=> $v) {
            $where = array();
            foreach ($v as $key=>$value) {
                if (strpos($key, $char) === false) {
                    $where[$key] = $value;
                }
            }
            $this->where[$k] = $where;
        }
    }
    
    private function parseWhereVarFieldsExplain()
    {
        foreach ($this->where as $k=> $v) {
            foreach ($v as $key=> $value) {
                $r_explain = '/(.*?)\s*\{(.*?)\}\s*/';
                preg_match($r_explain, $value, $matches);
                if (isset($matches[0])) {
                    $this->where[$k][$matches[1]] = $matches[2];
                } else {
                    $this->where[$k][$value] = '';
                }
                unset($this->where[$k][$key]);
            }
        }
    }
    
    private function usePostedValues2FillWhereFields()
    {
        /*
         *用上传的where参数进行填充 
         */
        foreach ($this->where as $k=> $v) {
            foreach ($v as $key=> $val) {
                $target = &$this->where[$k][$key];
                $target = '';
                if (isset($this->params['where']['fields'][$key])) {
                    $replace = $this->params['where']['fields'][$key];
                    $this->simpleSetKeyValue($target, $replace);
                }
            }
        }
    }
    
    private function simpleSetKeyValue(&$target, $replace)
    {
        $target = $replace;
    }

    private function fillWhereFieldsWithSharedValue()
    {
        /*
         * 解析共享变量并用第一次填充的值来进行逻辑替换
         */
        $regexp = '/(\w+)\.(\d+)\.(.*)/i';
        foreach ($this->where as $k=> $v) {
            foreach ($v as $key=> $val) {
                if (strpos($key, ':') !== false) {
                    unset($this->where[$k][$key]);
                    list($field, $link) = explode(':', $key);
                    $field = trim($field);
                    $link  = trim($link);
                    preg_match($regexp, $link, $matches);
                    if (isset($matches[0])) {
                        $_val = '';
                        list($str, $area, $path, $_field) = $matches;
                        if (isset($this->where[$path-1][$_field])) {
                            $_val = $this->where[$path-1][$_field];
                        }
                        $this->where[$k][$field] = $_val;
                    }
                }
            }
        }
    }
    
    private function trimFieldsSuffix(&$result)
    {
        /*
         * 剔除字段的.符号,以.分隔的第一个字段进行替换
         */
        foreach ($result as $k=> $v) {
            foreach ($v as $key=> $val) {
                $seg = explode('.', $key);
                // . concat must have 2 elements
                if (count($seg) > 1) {
                    $result[$k][$seg[0]] = $val;
                    unset($result[$k][$key]);
                }
            }
        }
    }
    
    private function createWhereConditionsWithOperatorAndCombineConstWhere()
    {

        /*
         * 用上传的字段、运算符生成where条件并且合并常量条件
         */
        foreach ($this->where as $k=> $v) {
            $where = array();
            foreach ($v as $key=> $val) {
                if ($val == '') { continue; }
                if (is_array($val)) {
                    $where[] = $this->getInnerCondition($key, $val);
                }
                if (is_string($val)) {
                    $r_map = '/\s*map\s*\{\s*(.*)\s*\}.*/i';
                    preg_match($r_map, $val, $matches);
                    if (isset($matches[0])) {
                        list($str, $map) = $matches;
                        $where[] = $this->getMapConditionAndSaveInputMapGroup($key, $map);
                        $this->mapFields[$key] = $key;
                    } else {
                        $val = explode(',', str_replace('|', ',', $val));
                        $this->trimArray($val);
                        $where[] = $this->getInnerCondition($key, $val);
                    }
                }
            }
            $constWhere = isset($this->constWhere[$k]) ? $this->constWhere[$k] : array();
            $this->where[$k] = count(array_merge($where, $constWhere)) >0 ? 
                'where '. implode(' and ', array_merge($where, $constWhere)) : '';
        }
    }
    
    private function trimArray(&$array)
    {
        array_walk($array, function(&$v) {
            $v = trim($v);
        });
    }
    
    private function getInnerCondition($key, $value)
    {
        /*
         * 解析字段的多个或单个值,用上传的运算符生成最小单位的where条件
         */
        $where = array();
        $operators = $this->getTransformedWhereOperators();
        $operator = isset($operators[$key]) ? $operators[$key] : '';
        foreach ($value as $k=> $v) {
            $where[] = $this->getParsedOperator($key, $v, $operator);
        }
        if (count($where) > 1) {
            return '('. implode(' or ', $where) . ')';
        }
        return implode(' or ', $where);
    }

    private function getMapConditionAndSaveInputMapGroup($key, $map)
    {
        /*
         * 解析map类型的where条件并且保存map类型的聚合字段
         * segs示例：
         * action : a{equal} , event:222, action:b{like_left}
         */
        $where = array();
        $result = array();
        $str = '';
        $lastKey = '';
        $segs = explode(',', str_replace(array(',,'), ',', $map));
        // for the purpose of sort, v should ltrim
        array_walk($segs, function (&$v) { $v = ltrim($v);});
        sort($segs);
        // print_r($segs);
        foreach ($segs as $k=> $seg) {
            preg_match('/\s*(\w+)\s*\:(.*)(\{(\s*(\w+)\s*)\})?\s*/i', trim($seg), $matches);
            // print_r($matches);
            if (isset($matches[0])) {
                list($str, $k, $v) = $matches;
                preg_match('/(.*)\{(.*?)\}.*/i', $v, $sub_matches);
                // print_r($sub_matches);
                if (isset($sub_matches[0])) {
                    list($str, $val, $operator) = $sub_matches;
                } else {
                    $val = $v;
                    $operator = '';
                }
                // echo "lastKey:$lastKey,k:$k\n";
                if ($lastKey !='' && $k != $lastKey) {
                    $this->implodeMapWhere($result, $where);        
                    $where = array();
                }
                $where[] = $this->getParsedOperator("{$key}['{$k}']", $val, $operator);
                $lastKey = $k;
            } else {
                
                $arr = array_filter(explode(",", $seg));
                array_walk($arr, function (&$v) use ($key){
                    $v = trim($v);
                    $v = "{$key}['{$v}']";
                });
                $this->inputMapGroup[$key][] = implode(',', $arr);
            }
            
        }
        if ($where) {
            $this->implodeMapWhere($result, $where);        
        }
        return implode(' and ', $result);
    }

    private function implodeMapWhere(&$result, $where)
    {
        if (count($where) > 1) {
            $result[] = '('. implode(' or ', $where). ')';
        }
        if (count($where) == 1){
            $result[] = implode(' or ', $where);
        }
    }
    
    private function getTransformedWhereOperators()
    {
        $operators = array();
        foreach ($this->params['where']['operators'] as $k=> $v) {
            $seg = explode('.', $k);
            if (isset($seg[0])) {
                $operators[$seg[0]] = $v;
            }
        }
        return $operators;
    }
    
    public static function getOperatorMap()
    {
        return array(
            'equal'         => '等于',
            'less_than'     => '小于',
            'greater_than'  => '大于',
            'like_contain'  => '包含',
            'range'         => '区间',
            'regexp'        => '正则',
            'not_equal'     => '不等',
            'like_left'     => '开头',
            'like_right'    => '结尾',
            'not_like'      => '不含',
        );
    }
    
    private function getParsedOperator($k, $v, $operator)
    {
        /*
         * 根据指定的键值、运算符生成条件式
         */
        if ($operator == '') return "$k='{$v}'";
        if ($operator == 'equal') return "$k='{$v}'";
        if ($operator == 'not_equal') return "$k!='{$v}'";
        if ($operator == 'less_than') return "$k<'{$v}'";
        if ($operator == 'greater_than') return "$k>'{$v}'";
        if ($operator == 'like_left') return "$k like '%{$v}'";
        if ($operator == 'like_right') return "$k like '{$v}%'";
        if ($operator == 'like_contain') return "$k like '%{$v}%'";
        if ($operator == 'regexp') return "$k regexp '{$v}'";
        if ($operator == 'not_regexp') return "not $k regexp '{$v}'";
        if ($operator == 'not_like') return "not $k like '%{$v}%'";
        if ($operator == 'range') {
            list($min, $max) = explode(',', str_replace(array('~'), ',', $v));
            $min = trim($min);
            $max = trim($max);
            return "$k>='$min' and $k<='$max'";
        }
    }
    
    private function replaceWhereArea()
    {
        if (!$this->where) return;
        $where = $this->parseArea('/.*?(<{\s*where\s+.*?\s*}>).*?/is');
        $this->query = str_replace($where, $this->where, $this->query);
    }

    
    private function replaceInputMapGroup(&$inputGroup)
    {
        array_walk($inputGroup, function (&$v, $k) {
            if ($v) {
                if (isset($this->inputMapGroup[$k])) {
                    $v = implode(',', $this->inputMapGroup[$k]);
                } else {
                    $v = $k;
                }
            }
        });
    }
    
    private function replaceGroupArea()
    {
        /*
         * 根据上传的group字段,结合解析过后的map类型的group，
         * 生成最终的group字段并进行替换
         */
        if (!$this->group) return;
        $inputGroup = array();
        // 如果该字段是map类型且指定了key则聚合该字段，为了避免直接聚合map字段而产生SQL语法报错
        foreach ($this->params['group'] as $k=> $v) {
            if ($v) {
                if (isset($this->mapFields[$k])) {
                    if (isset($this->inputMapGroup[$k])) {
                        $inputGroup[$k] = $v;
                    }
                } else {
                    $inputGroup[$k] = $v;
                }
            }
        }
        
        // 对于map类型的select则不需要考虑map key是否指定，也就是说select map类型是语法可以通过的
        $inputGroupOfSelect = array_filter($this->params['group'], function ($v) {
            if ($v) return $v;
        });
        
        // replaceInputMapGroup：如果是map类型且存在key则直接替换组织好的map[key]，否则仅替换map字段
        $this->replaceInputMapGroup($inputGroup);
        $this->replaceInputMapGroup($inputGroupOfSelect);
        $this->inputMapGroup = array_values($inputGroupOfSelect);
        $replace[] = !empty($inputGroup) ? 'group by ' . implode(',', array_values($inputGroup)) : '';
        $r_group = '/.*?(<{\s*group\s+by\s+.*?\s*}>).*?/is';
        $groups = $this->parseArea($r_group);
        $this->query = str_replace($groups, $replace, $this->query);
    }
    
    private function replaceOrderArea()
    {
        if (!$this->order) return;
        $inputOrder = array();
        foreach ($this->params['order'] as $k=> $v) {
            $v = trim($v);
            $inputOrder[] = "$k $v";
        }
        if (count($inputOrder) !=0 ) {
            $this->order = implode(',', $inputOrder);
            $replace[] = 'order by ' . implode(',', $inputOrder);
        } else {
            $replace[] = '';
        }
        $r_order = '/.*?(<{\s*order\s+by\s+.*?\s*}>).*?/is';
        $orders = $this->parseArea($r_order);
        $this->query = str_replace($orders, $replace, $this->query);
        
    }
    
    private function replaceLimitArea()
    {
        if (!$this->limit) return;
        $this->limit = $this->params['limit'];
        $replace[] = 'limit ' . $this->params['limit'];
        $r_limit = '/.*?(<{\s*limit\s+.*?\s*}>).*?/is';
        $limits = $this->parseArea($r_limit);
        $this->query = str_replace($limits, $replace, $this->query);
    }
    
    private function replaceSelectArea()
    {
        /*
         *根据select域中的内容再次解析字段和字段解释，结合上传的聚合参数进行筛选，
         *并合并需要聚合的map类型字段，生成最后的select字段
         */
        if (!$this->select) return;
        $inputGroup = $this->params['group'];
        $explainMap = array();
        $selectFields = array();
        $selects = explode(',', $this->select[0]);
        // 解析模版字段自带的解释
        foreach ($selects as $k=> $v) {
            $v = trim($v);
            preg_match('/(.*)\{(.*)\}\s*/', $v, $matches);
            if (isset($matches[0])) {
                list($str, $field, $desc) = $matches;
                $selectFields[$field] = $desc;
                $explainMap[$field] = $desc;
            } else {
                $selectFields[$v] = $v;
            }
        }
        // 删除未选的聚合字段
        foreach ($selectFields as $k=> $v) {
            foreach ($inputGroup as $key=> $val) {
                if ($val == 0) {
                    unset($selectFields[$key]);
                }
            }
        }
        
        //print_r($this->inputMapGroup);
        //print_r(array_keys($selectFields));
        
        $replace[] = 'select ' . implode(',', 
            array_unique(array_merge($this->inputMapGroup, array_keys($selectFields))));
        $r_select = '/.*?(<{\s*select\s+.*?\s*}>).*?/is';
        $selects = $this->parseArea($r_select);
        $this->query = str_replace($selects, $replace, $this->query);
        
        $headerFields = array_unique(array_merge($this->inputMapGroup, array_keys($selectFields)));
        foreach ($headerFields as $k=> $v) {
            $headerFields[$k] = isset($explainMap[$v]) ? $explainMap[$v] : $v;
        }
        $this->header = implode(',', $headerFields);
    }

    private function getRenderWhereFieldsExplainAndType()
    {
        /*
         * 解析模版中的字段解释和绘制类型
         */
        foreach ($this->where as $k=> $v) {
            array_walk($v, function(&$v, $k) {
                preg_match('/\s*(.*?):(.*)\s*/', $v, $matches);
                if (isset($matches[0])) {
                    list($str, $explain, $type) = $matches;
                    $v = array(
                        'explain' => $explain,
                        'type'    => $type,
                        'value'   => '',
                        'operator'=> 'equal',
                    );
                } else {
                    $v = array(
                        'explain' => $k,
                        'type'    => 'text',
                        'value'   => '',
                        'operator'=> 'equal',
                    );
                }
            });
            $this->where[$k] = $v;
        }
    }
    
    private function trimTablePrefix(&$result)
    {
        array_walk($result, function (&$v, $k){
            $val = explode('.', $v);
            if (count($val) >=2) {
                $v = $val[1];
            }
        });
    }
    
    private function getTransformedParams()
    {
        /*
         * 根据历史提交的参数(没有就构造)附带模版信息(字段解释和 绘制类型)返回给前端
         */
        $combined = array();
        foreach ($this->where as $k=> $v) {
            $combined = array_merge($combined, $v);
        }
        
        if(!$this->params) {
            $this->params['where']['fields'] = $combined;
            $this->params['where']['operators'] = array_combine(array_keys($combined),
                array_fill(0, count($combined), 'equal'));
            $group = array();
            array_walk($this->group, function ($v, $k) use (&$group) {
                $group = array_values($v);
            });
            
            //$this->trimTablePrefix($group);
            $this->params['group'] = array_combine(array_values($group), 
                array_fill(0, count($group), 0));
            $this->params['limit'] = 50000000;
            return;
        }
        
        foreach ($this->params['where']['fields'] as $k=> $v) {
            $defaultOperator = 'equal';
            if (isset($this->params['where']['operators'][$k])) {
                $defaultOperator = $this->params['where']['operators'][$k];
            }
            if (isset($combined[$k])) {
                $v = array_merge($combined[$k], array(
                    'value' => $v,
                    'operator' => $defaultOperator,
                ));
            }
            $this->params['where']['fields'][$k] = $v;
        }
    }
}
