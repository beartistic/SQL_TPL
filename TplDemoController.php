<?php

namespace Test;

use Parser\QueryTpl;
use Caravel\Http\Input;
use Parser\Tpl;
use Template;

class TplDemoController {
    
    public $defaultPage = 1;
    public $defaultPageSize = 8;
    
    public $defaultLimit = 50000000;
    public static $query = <<<EOT
<{select a.br{品牌},b.md{机型},a.cnt{次数},a.user{人数}}> from
(select md,max(brand)brand from t2 <{where md.2{机型2:select},pday{日期:daterange|YYYYMMDD}, brand, model:where.2.md.1, event_seg,
brand not regexp '^xiaomi',   md != 'huawei'}> group by md)b
left outer join
(select md,br,ch,count(*)cnt,count(distinct m2)user from t1 <{where md.1{机型1:select},pday2{日期2:daterange|YYYY-MM-DD},br,ch,os,at<>'1',ch not regexp '^2'}> group by md,br,ch)a
on a.br=b.brand
<{group by a.pday,a.br,b.md, event_seg}>
<{limit offset}>
EOT;
    
    public static $params = array(
        'where' => array(
            'fields'   => array(
                'pday'=>'20170901 ~ 20170912',
                'pday2'=>'2017-09-01 ~ 2017-09-12',
                'md.1'=>'huawei p9, huawei p10',
                'md.2'=>'huawei p8',
                'br'=>'huawei',
                'ch'=>'100130~100131',
                'os'=>'24',
                'brand' => 'huawei',
                'event_seg' => "map { action : a{equal} , event:222, action:b{like_left}, action,k1,k2}",
            ),
            'operators' => array(
                'md.1'=>'not_equal',
                'br'=>'not_equal',
                'ch'=>'range',
                'os'=>'less_than',
                // k5=>'like_left',
                // k6=>'like_right',
                // k8=>'like_contain',
                // k7=>'regexp',
            ),
        ),
        'group' => array (
            'pday'=>'1',
            'br'=>'1',
            'md'=>'1',
            'event_seg' => '1',
        ),
        /*
        'having' => array (
            'fields'   => array(
                'md'=>'huawei p9',
                'br'=>'huawei',
            ),
            'operator' => array(
                'md'=>'equal',
                'br'=>'equal',
            ),
        ),
        'order' => array(
            'br'=>'desc',
            'md'=>'asc',
        ),
        */
        'limit' => 100,
    );
    
    
    /*
    public static $query = <<<EOT
select a.m2 from 
(select m2 from dwd_evt_sj_install_di <{where pname,pday.1{安装日期}}> group by m2)a
left outer join
(select m2 from dwd_evt_sj_install_di <{where pname:where.1.pname,pday.2{流失日期}}> group by m2)b
on a.m2=b.m2
where b.m2 is null;
EOT;*/

    public function renderWhatAction()
    {
        $query = self::$query;
        $params = self::$params;
        $tpl = new QueryTpl($query);
        $tpl->setQuery($query)->setParams(array());
        $fields = $tpl->getRenderFields();
        
        if (Input::get('t') == 'test') {
            print_r(json_encode($fields));
            return;
        }
        
        $id = Input::get('id');
        $table_name = "hive2_id{$id}";
        $page = $this->defaultPage;
        $pageSize = $this->defaultPageSize;
        $params = array('table_name' => $table_name);
        list($total, $records) = \Queue\Hive::getQuery($page, $pageSize, $params);
        $totalPages = ceil($total / $pageSize);
        $stateMap = \Helper\Queue::getStateMap();
        $options = $this->getFieldOptions($fields);
        

        return \View::make("tpl.index", array(
            'tpl_id'     => $id,
            'options'    => $options,
            'records'    => $fields,
            'history'    => $records,
            'total'      => $total,
            'page'       => $page,
            'totalPages' => $totalPages,
            'pageSize'   => $pageSize,
            'stateMap'   => $stateMap,
            'filters'    => array(
                'operator'  =>'查询者',
                'marker'    =>'备注'
            ),
        ));
        
    }
    
    
    public function getTrimedField($value, $char='.')
    {
        $values = explode($char, $value);
        if (count($values) >=2 ) {
            return $values[0];            
        }
        return $value;
    }
    
    
    public function getFieldOptions($fields)
    {
        $optionMap = array();
        foreach ($fields['where']['fields'] as $k=> $v) {
            $field_desc = "";
            $options = array();
            
            $key = $this->getTrimedField($k);
            $sourceInfo = Tpl::getSouceInfo($key);
            
            if ($sourceInfo) {
                $field_desc = $sourceInfo['field_desc'];
                if ($v['type'] == 'select') {
                    $options = Tpl::getOptions(array_values($sourceInfo));
                }
            }
            
            $optionMap[$k] = array (
                'desc'      => $field_desc,
                'options'   => $options,
            );
            
            // if field have alias options will trim
            $_k = $this->getTrimedField($k);
            $optionMap[$_k] = array (
                'desc'      => $field_desc,
                'options'   => $options,
            );
            
        }
        return $optionMap;        
    }
    
    
    public function runAction()
    {
        $query  = self::$query;
        $params = self::$params;
        
        /*
        $params = array(
            'where' => array(
                'fields'   => array(
                    'pday.1'=>'20170825',
                    'pday.2'=>'20170828',
                    'pname'=>'com.tencent.tmgp.sgame',
                ),
                'operators' => array(
                    'pday.1'=>'equal',
                    'pday.2'=>'equal',
                    'pname'=>'equal',
                ),
            )
        );
        */
        $tpl = new QueryTpl($query, $params);
        $tpl->run();
    }
    
    
    public function pushAction()
    {
        $where = Input::get("where");
        $group = Input::get("group");
        $operator = Input::get("operator");
        $tplParams = array(
            "where" => array(
                "fields" => $where,
                "operators" => $operator,
            ),
            "group" => $group,
            "limit" => $this->defaultLimit,
        );
        $tpl = new QueryTpl(self::$query, $tplParams);
        $query = $tpl->run();
        print_r($query);
    }
    
    
    public function getListAction()
    {
        $id = Input::get('id');
        $page = Input::get('page', $this->defaultPage);
        $pageSize = $this->defaultPageSize;
        
        $columns = Input::get('columns', false);
        if (!$columns) {
            throw new \Exception("query table column invalid");
        }
        
        $params = array();
        $columns = array_filter(explode(',', $columns));
        foreach ($columns as $k=> $v) {
            $params[$v] = Input::get($v, '');
        }
        
        list($total, $records) = Tpl::getQueryTaskList($page, $pageSize, $params);
        $totalPages = ceil($total / $pageSize);
        $stateMap = \Helper\Queue::getStateMap();
        
        return \View::make("tpl.result", array(
            'history'    => $records,
            'total'      => $total,
            'page'       => $page,
            'totalPages' => $totalPages,
            'pageSize'   => $pageSize,
            'stateMap'   => $stateMap,
        ));
    }
    
    
    public function markAction()
    {
        $marker = \Input::get('marker', '');
        $id = Input::get('id', 0);
        Tpl::mark($id, $marker);
    }
    
    
    public function fillAction()
    {
        $id = Input::get("id", 0);
        $query = self::$query;
        $params = self::$params;
        $tpl = new QueryTpl($query);
        $tpl->setQuery($query)->setParams($params);
        $fields = $tpl->getRenderFields();
        $options = $this->getFieldOptions($fields);
        return \View::make("tpl.inputgroup", array(
            'options'    => $options,
            'records'    => $fields,
        ));
        
    }
    
}
