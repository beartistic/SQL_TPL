>用途：查询复用，避免人工多次处理同一个需求
1. 模版
基本定义：
<{}>标识定义解析串，有select 、where、group三种类型，包裹起来的字符串称为变量域，比如<{where ...}，则称为where变量域
where变量域
where变量域中包含变量字段和常量字段，多个变量常量用英文逗号,分隔
单个字段就是变量字段，可附带其他信息，比如{字段解释:绘制类型}标识定义字段中文解释和绘制类型，模板中不指定字段解释则取默认mysql对照表(对照关系可后台维护)、绘制类型默认text
形如k=v形式的是常量字段
绘制类型有文本text、下拉选项select、日期类型daterange 三种类型。
不指定默认是文本类型；下拉选项可通过维表field_map指定，需要指定field(字段)、field_desc(字段解释)、table_name(引用维表)、target_field(引用字段和字段解释，用英文冒号:分隔)
特别的，日期在此可指定格式，比如日期类型为20170925的，可指定pday{日期:daterange|YYYYMMDD}
. 点符号在where变量域中表示别名和引用：
表示别名，用于指定相同字段不同含义的key，比如day在新增里表示新增日期，在下载里表示下载日期可以使用.结合{}自定义这两个字段，比如day.1{新增日期} day.2{下载日期}
表示引用，结合:使用时，:表示等于,可引用where域中任意key的值,比如model:where.1.md表示model等于第一个where条件中md字段的值
select变量域
可手动指定查询字段解释，比如<{select a.br{品牌},b.md{机型},a.cnt{次数},a.user{人数}}>
group变量域
说明暂无
 
规则约定：
1.查询最外层必须声明select
2.若字段值指定为map{f}, 则表示该字段为map类型, f为kv或者k形式并且可组合 , kv设置了where条件，k不设置where条件，k可作聚合字段中的key，比如group by event_seg[k]
a.不指定v时&&勾选该聚合字段,比如map{k,k1},则group by field[k],field[k1],where不设置条件; 
b.指定v时&&勾选该聚合字段，比如map{k:v{equal},k:v1{not_equal},k2:v2{like_left}},若存在多个相同k,不同v时，条件或关系，否则与关系, equallike_left代表运算符
3.对于map类型的group by只能作用于单个查询中，不能在嵌套中使用，因为子查询无法解析出map字段
map类型可指定的运算符
'equal' => '等于',
'less_than' => '小于',
'greater_than' => '大于',
'like_contain' => '包含',
'range' => '区间',
'regexp' => '正则',
'not_equal' => '不等',
'like_left' => '开头',
'like_right' => '结尾',
'not_like' => '不含',
map字段输入示例和解析结果
map { action : a,k1,k2} 解析结果：select pday,event_seg['k1'],event_seg['k2'],count(*),count(distinct m2) from dwd_evt_sj_qother_hi where event_seg['action']=' a' group by pday,event_seg['k1'],event_seg['k2']
map { action : a{equal} , event:222, action:b{like_left}} 解析结果：select pday,event_seg,count(*),count(distinct m2) from dwd_evt_sj_qother_hi where (event_seg['action']=' a' or event_seg['action'] like '%b') and event_seg['event']='222' group by pday
 
2.模版示例
<{select a.br{品牌},b.md{机型},a.cnt{次数},a.user{人数}}> from
(select md,max(brand)brand from t2 <{where md.2{机型2},brand,model:where.2.md.1,event_seg,brand not regexp '^xiaomi', md != 'huawei'}> group by md)b
left outer join
(select md,br,ch,count(*)cnt,count(distinct m2)user from t1 <{where md.1{机型1},br,ch,os,at<>'1',ch not regexp '^2'}> group by md,br,ch)a
on a.br=b.brand
<{group by a.br,b.md}>
 

