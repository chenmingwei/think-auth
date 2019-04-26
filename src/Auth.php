<?php

namespace think\auth;

use think\Db;
use think\facade\Config;
use think\facade\Request;

/**
 * 权限认证类
 * 功能特性：
 * 1，是对规则进行认证，不是对节点进行认证。用户可以把节点当作规则名称实现对节点进行认证。
 *      $auth=new Auth();  $auth->check('规则名称','用户id')
 * 2，可以同时对多条规则进行认证，并设置多条规则的关系（or或者and）
 *      $auth=new Auth();  $auth->check('规则1,规则2','用户id','and')
 *      第三个参数为and时表示，用户需要同时具有规则1和规则2的权限。 当第三个参数为or时，表示用户值需要具备其中一个条件即可。默认为or
 * 3，一个用户可以属于多个用户组(think_auth_group_access表 定义了用户所属用户组)。我们需要设置每个用户组拥有哪些规则(think_auth_group 定义了用户组权限)
 *
 * 4，支持规则表达式。
 *      在think_auth_rule 表中定义一条规则时，如果type为1， condition字段就可以定义规则表达式。 如定义{score}>5  and {score}<100  表示用户的分数在5-100之间时这条规则才会通过。
 */
//数据库
/*
-------------------------------------------------
 think_auth_rule，规则表，
- id:主键，
- name：验证规则, 
- title：规则中文名称 
- type: 0普通权限节点； 1菜单权限节点
- status 状态：为1正常，为0禁用，
- icon：图标
-------------------------------------------------
 DROP TABLE IF EXISTS `think_auth_rule`;
CREATE TABLE `think_auth_rule` (
  `id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `name` char(80) NOT NULL DEFAULT '' COMMENT '验证规则',
  `title` char(20) NOT NULL DEFAULT '' COMMENT '规则中文名称',
  `type` tinyint(4) NOT NULL DEFAULT '0' COMMENT '0:普通权限节点；1:菜单权限节点',
  `pid` int(11) NOT NULL DEFAULT '0' COMMENT '父级ID',
  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '状态：为1正常，为0禁用',
  `icon` varchar(80) DEFAULT NULL COMMENT '图标',
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8 COMMENT='权限规则表'

-------------------------------------------------
- think_auth_group 用户组表， 
- id：主键， 
- title: 用户组中文名称， 
- status：用户组状态  0禁用  1启用
-------------------------------------------------
 DROP TABLE IF EXISTS `think_auth_group`;
CREATE TABLE `think_auth_group` (
  `id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `title` char(100) NOT NULL DEFAULT '' COMMENT '标题',
  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '状态',
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNIQUE` (`title`)
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8 COMMENT='用户组表'

-------------------------------------------------
- think_auth_group_access 用户与用户组关系表
- uid:用户id，
- group_id：用户组id
-------------------------------------------------
DROP TABLE IF EXISTS `think_auth_group_access`;
CREATE TABLE `think_auth_group_access` (
  `uid` mediumint(8) unsigned NOT NULL,
  `group_id` mediumint(8) unsigned NOT NULL,
  UNIQUE KEY `uid_group_id` (`uid`,`group_id`),
  KEY `uid` (`uid`),
  KEY `group_id` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='用户与用户组关系表'

-------------------------------------------------
- think_auth_group_rule 用户组与规则关系表
- rid: 权限点rule id，
- group_id：用户组id
-------------------------------------------------
DROP TABLE IF EXISTS `think_auth_group_rule`;
CREATE TABLE `think_auth_group_rule` (
  `rid` int(11) unsigned NOT NULL,
  `group_id` int(11) unsigned NOT NULL,
  UNIQUE KEY `uid_group_id` (`rid`,`group_id`),
  KEY `rid` (`rid`),
  KEY `group_id` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='用户组与规则关系表'
 */

class Auth
{
    /**
     * @var object 对象实例
     */
    protected static $instance;
    /**
     * 当前请求实例
     * @var Request
     */
    protected $request;

    //默认配置
    protected $config = [
        'auth_on' => 1, // 权限开关
        'auth_type' => 2, // 认证方式，1为实时认证；2为登录认证。
        'auth_group' => 'erp_auth_group', // 用户组数据表名
        'auth_group_access' => 'erp_auth_group_access', // 用户-用户组关系表
        'auth_group_rule' => 'erp_auth_group_rule', // 用户组-权限规则关系表
        'auth_rule' => 'erp_auth_rule', // 权限规则表
        'auth_user' => 'erp_user', // 用户信息表
    ];

    /**
     * 类架构函数
     * Auth constructor.
     */
    public function __construct()
    {
        //可设置配置项 auth, 此配置项为数组。
        $auth = Config::pull('auth');
        if ($auth) {
            $this->config = array_merge($this->config, $auth);
        }
        $this->config['auth_group'] = Config::get('database.prefix') . $this->config['auth_group'];
        $this->config['auth_group_access'] = Config::get('database.prefix') . $this->config['auth_group_access'];
        $this->config['auth_group_rule'] = Config::get('database.prefix') . $this->config['auth_group_rule'];
        $this->config['auth_rule'] = Config::get('database.prefix') . $this->config['auth_rule'];
        $this->config['auth_user'] = Config::get('database.prefix') . $this->config['auth_user'];
            // 初始化request
        $this->request = Request::instance();
    }

    /**
     * 初始化
     * @access public
     * @param array $options 参数
     * @return \think\Request
     */
    public static function instance($options = [])
    {
        if (is_null(self::$instance)) {
            self::$instance = new static($options);
        }
        return self::$instance;
    }

    /**
     * 检查权限
     * @param $uid  int     认证用户的id
     * @param $name string  需要验证的规则列表
     * @return bool         通过验证返回true;失败返回false
     */
    public function check($uid, $name='')
    {
        // 是否关闭权限验证
        if (!$this->config['auth_on']) {
            return true;
        }
        // 配置不需要验证的路由
        $noauth = $this->config['public'];
        if($noauth){
            foreach($noauth as $item){
                if($name == $item)
                    return true;
            }
        }
        // 已获取的权限
        $authLists = $this->getAuthList($uid);
//        return $authLists;
        foreach($authLists as $item){
            if($name == $item['name'])
                return true;
        }
        return false;
    }

    /**
     * 根据用户id获取用户组,返回值为数组
     * @param  $uid int     用户id
     * @return array        用户所属的用户组
    [
    [
    'uid'=>'用户id',
    'group_id'=>'用户组id',
    'title'=>'用户组名称'
    ],
    ...
    ]
     */
    protected function getGroups($uid)
    {
        static $groups = [];
        if (isset($groups[$uid])) {
            return $groups[$uid];
        }
        $user_groups = Db::query($this->getGroupsSql($uid));
        $groups[$uid] = $user_groups ?: [];

        return $groups[$uid];
    }

    /**
     * 添加一个权限组
     * @param null $title
     * @param int $status
     * @return bool|int|string
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function addGroups($title = null, $status=1){
        if(!$title){
            exception('缺少参数:title');
        }
        $res = Db::table($this->config['auth_group'])
            ->where("title", $title)->find();
        if($res) return false;

        return Db::table($this->config['auth_group'])
            ->insert(['title'=>$title, 'status'=>$status]);
    }

    /**
     * 改变权限组状态
     * @param $group_id  用户组ID
     * @param int $status  状态
     * @return int|string
     * @throws \think\Exception
     * @throws \think\exception\PDOException
     */
    public function changeGroupsStatus($group_id = null, $status = 0){
        if(!$group_id){
            exception('缺少参数:group_id');
        }
        return Db::table($this->config['auth_group'])->where("id = {$group_id}")->update(['status'=> $status]);
    }

    /**
     * 为一个组设置权限
     * @param $group_id 用户组ID
     * @param $rules  权限节点ID ， int|array
     * @return int|string
     * @throws \Exception
     */
    public function setAuthOfGroup($group_id, $rules){
        if(!$group_id || !$rules){
            exception('缺少参数');
        }
        $data = [];
        if(is_array($rules)){
            foreach($rules as $k => $v){
                $data[$k]['group_id'] = $group_id;
                $data[$k]['rid'] = $v;
            }
        } else {
            $data[0]['group_id'] = $group_id;
            $data[0]['rid'] = $rules;
        }

        return Db::table($this->config['auth_group_rule'])
            ->insertAll($data, true);
    }

    /**
     * 按用户查询用户组
     * @param $uid
     * @return array|\PDOStatement|string|\think\Collection
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getGroupsByUid($uid){
        // 转换表名
        $auth_group_access = $this->config['auth_group_access'];
        $auth_group = $this->config['auth_group'];
        // SQL生成
        $data = Db::view($auth_group_access, 'uid,group_id')
            ->view($auth_group, 'title,status', "{$auth_group_access}.group_id={$auth_group}.id", 'LEFT')
            ->where("{$auth_group_access}.uid='{$uid}'")
            ->select();
        return $data;
    }

    /**
     *  按组查询用户
     *   @param $uid
     */
    public function getUsersByGroup($group_id){
        // 转换表名
        $auth_group_access = $this->config['auth_group_access'];
        $auth_user = $this->config['auth_user'];
        // SQL生成
        $data = Db::view($auth_group_access, 'uid,group_id')
            ->view($auth_user, 'id,name,niekname', "{$auth_group_access}.uid={$auth_user}.id")
            ->where("{$auth_group_access}.group_id='{$group_id}'")
            ->select();
        return $data;
    }

    /**
     * 为用户添加用户组
     * @param $uid
     * @param $groups_id  int|array
     * @return int|string
     * @throws \Exception
     */
    public function addGroupsByUid($uid, $groups_id){
        if(!$uid || !$groups_id){
            exception('缺少参数');
        }
        $data = [];
        if(is_array($groups_id)){
            foreach($groups_id as $k => $v){
                $data[$k]['uid'] = $uid;
                $data[$k]['group_id'] = $v;
            }
        } else {
            $data[0]['uid'] = $uid;
            $data[0]['group_id'] = $groups_id;
        }

        return Db::table($this->config['auth_group_access'])
            ->insertAll($data, true);
    }

    /**
     * 往用户组中添加用户
     * @param $group_id
     * @param $uids  int|array
     * @return int|string
     * @throws \Exception
     */
    public function addUsersByGroup($group_id, $uids){
        if(!$uids || !$group_id){
            exception('缺少参数');
        }
        $data = [];
        if(is_array($uids)){
            foreach($uids as $k => $v){
                $data[$k]['group_id'] = $group_id;
                $data[$k]['uid'] = $v;
            }
        } else {
            $data[0]['group_id'] = $group_id;
            $data[0]['uid'] = $uids;
        }

        return Db::table($this->config['auth_group_access'])
            ->insertAll($data, true);
    }

    /**
     * 删除用户和用户组的关系
     * @param $uid   用户ID 支持数组
     * @param $groups_id   用户组ID  支持数组
     * @return int
     * @throws \think\Exception
     * @throws \think\exception\PDOException
     */
    public function delUserGroupsRelation($uids, $groups_id){
        if(!$uids || !$groups_id){
            exception('缺少参数');
        }
        return Db::table($this->config['auth_group_access'])
            ->where([
                'uid' => $uids,
                'group_id' => $groups_id
            ])
            ->delete();
    }

    /**
     * 删除用户组的权限
     * @param $groups_id  用户组ID 支持数组
     * @param $rules    规格ID  支持数组
     * @return int
     * @throws \think\Exception
     * @throws \think\exception\PDOException
     */
    public function delRulesByGroup($groups_id, $rules){
        if(!$uids || !$groups_id){
            exception('缺少参数');
        }
        return Db::table($this->config['auth_group_rule'])
            ->where([
                'rid' => $rules,
                'group_id' => $groups_id
            ])
            ->delete();
    }

    //添加权限节点
    public function addAuthRule($data){

    }

    //删除权限节点
    public function delAuthRule($rid){

    }

    /**
     * 按用户组查询出权限节点
     * @param $group_id
     * @return array|\PDOStatement|string|\think\Collection
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     * array(8) {
            ["rid"] => NULL
            ["group_id"] => NULL（为空说明该组对此节点没有权限）
            ["id"] =>    节点ID
            ["name"] =>  节点名
            ["title"] => 标题
            ["type"] =>  类型  0普通节点  1菜单节点
            ["pid"] =>   父级ID
            ["status"] => 状态 是否禁用
        }
     */
    public function getAuthRulesByGroup($group_id){
        // 数据表名处理
        $auth_group_rule = $this->config['auth_group_rule'];
        $auth_rule = $this->config['auth_rule'];
        // 子查询SQL
        $sql = Db::table($auth_group_rule)
            ->where('group_id',$group_id)->buildSql();
        // SQL查询
        $data = Db::table($sql . ' a')
            ->join($auth_rule, "a.rid = {$auth_rule}.id",'right')
            ->select();
        return $this->getTree($data);
    }

    /**
     * 按用户查询所有该用户有权限的菜单节点
     * @param $uid 用户ID
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getAuthMenuByUser($uid){
        $cacheKey = '_user_menu_tree_data_' . $uid;
        $cacheVal =  cache($cacheKey);
        if($cacheVal) return $cacheVal;
        $data = $this->getAuthList($uid);
        $temp = [];
        foreach($data as $v){
            if($v['type'] == 1) $temp[] = $v;
        }
        $temp = $this->getTree($temp);
        cache($cacheKey, $temp);
        return $temp;
    }

    // 无限分类树结构
    protected function getTree($data, $pid = 0){
        $tree = [];
        foreach ($data as $k => $v) {
            if ($v['pid'] == $pid) {
                $v['child'] = $this->getTree($data, $v['id']);
                $tree[] = $v;
            }
        }
        return $tree;
    }

    /**
     * 生成查询用户的所有效的权限组SQL
     * @param $uid  用户的ID
     * @return string  返回SQL用于查询和子查询
     * @throws \think\exception\DbException
     *
     */
    protected function getGroupsSql($uid){
        // 转换表名
        $auth_group_access = $this->config['auth_group_access'];
        $auth_group = $this->config['auth_group'];
        // SQL生成
        $sql = Db::view($auth_group_access, 'uid,group_id')
            ->view($auth_group, 'title', "{$auth_group_access}.group_id={$auth_group}.id", 'LEFT')
            ->where("{$auth_group_access}.uid='{$uid}' and {$auth_group}.status='1'")
//            ->fetchSql(true)
            ->buildSql();
        return $sql;
    }

    /**
     * 根据用户ID查询出所有有权限的rules
     * @param $uid  用户ID
     * @return array|mixed|\PDOStatement|string|\think\Collection
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    protected function getAuthList($uid)
    {
        // 走缓存
        $cacheKey = '_auth_rules_list_' . $uid;
        if($this->config['auth_type'] == 2){
            $cacheVal =  cache($cacheKey);
            if( $cacheVal) {
                return$cacheVal;
            }
        }
        // 数据表名处理
        $auth_group_rule = $this->config['auth_group_rule'];
        $auth_group = $this->config['auth_group'];
        $auth_rule = $this->config['auth_rule'];
        $auth_group_access = $this->config['auth_group_access'];
        // 生成根据用户查询出所有有效权限组ID 用于子查询的SQL
        $UserGroupsIds = Db::table($this->getGroupsSql($uid) .  'usergroup')
            ->field('usergroup.group_id')
            ->buildSql();
        // 一条SQL查出用户所有有效的权限
        $authRules = Db::view($auth_group_rule, 'rid')
            ->view($auth_rule, 'id,name,title,type,pid', "{$auth_group_rule}.rid = {$auth_rule}.id")
            ->where("{$auth_group_rule}.group_id in {$UserGroupsIds}")
            ->group("{$auth_group_rule}.rid")
            ->select();
        // 是否走缓存
        if($this->config['auth_type'] == 2){
            cache($cacheKey, $authRules);
        }
        return $authRules;
    }



}