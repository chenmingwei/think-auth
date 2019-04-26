# think-addons
The ThinkPHP5.1 Auth Package
一个ThinkPHP5.1  基于用户组的节点/菜单 权限管理类
部分代码参考   5ini99 / think-auth    

## 安装
> composer require chenmingwei/think-auth


## 配置
### 公共配置
```
// auth配置,在config目录下新建一个 auth.php 配置文件
config = [
    'auth_on'            =>   1, // 权限开关
    'auth_type'          =>   2, // 认证方式，1为实时认证；2为登录认证。
    'auth_group'         =>  'auth_group',  // 用户组数据表名
    'auth_group_access'  =>  'auth_group_access', // 用户-用户组关系表
    'auth_group_rule'    =>  'auth_group_rule', // 用户组-权限规则关系表
    'auth_rule'          =>  'auth_rule', // 权限规则表
    'auth_user'          =>  'user', // 用户信息表
    // 不需要验证权限的
    'public'             => [
            'index/index/index',
            'index/index/login'
    ]
];
```

### 导入数据表
> `think_` 为自定义的数据表前缀

```
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
  `sort` int(11) NOT NULL DEFAULT '0' COMMENT '排序',
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
```

## 原理
Auth权限认证是按规则进行认证。
在数据库中我们有 

- 规则表（think_auth_rule） 
- 用户组表(think_auth_group) 
- 用户与用户组关系表（think_auth_group_access）
- 用户组与规则关系表（think_auth_group_rule）

我们在think_auth_rule表中定义权限规则，在think_auth_group中定义用户组， 在think_auth_group_rule中定义哪些用户组与规格的关系，在think_auth_group_access中定义用户与用户组的关系。 

下面举例说明：

我们要判断用户是否有显示一个操作按钮的权限， 首先定义一个规则， 在规则表中添加一个名为 show_button 的规则。 然后在用户组表添加一个用户组，定义这个用户组有show_button 的权限规则， 然后在用户组明细表定义 UID 为1 的用户 属于刚才这个的这个用户组。 

## 使用
判断权限方法
```
// 引入类库
use think\auth\Auth;

// 获取auth实例
$auth = Auth::instance();

// 检测权限
if($auth->check(1, 'show_button')){// 第一个参数用户ID,第二个参数是规则名称
	//有显示操作按钮的权限
}else{
	//没有显示操作按钮的权限
}
```

Auth类也可以对节点进行认证，我们只要将规则名称，定义为节点名称就行了。 
可以在公共控制器Base中定义initialize方法
```
<?php
namespace app\common\controller;

use think\auth\Auth;


class Base extends Common
{
    protected $auth;
    protected function initialize()
    {
        parent::initialize();

        $module = request()->module();
        $controller = request()->controller();
        $action = request()->action();
        $rule = strtolower($module) . '/' . strtolower($controller) . '/' . strtolower($action);
        // 登陆验证 自行处理
        $uid = session('userinfo.id');
        if(!$uid){
            $this->redirect('admin/login/index');
        }
        // 权限验证
        $this->auth = new Auth();
        if(!$this->auth->check($uid, $rule)){
            $this->error('权限不足');
        }
    }
}
```
这时候我们可以在数据库中添加的节点规则， 格式为： “模块/控制器名称/方法名称”
需要做权限控制的控制器都继承该基类


为了方便使用我们为Auth类拓展了几个实用方法
```
    $auth = new Auth();

    /**
     * 添加一个权限组
     * @param $title 组名称|必填 
     * @param int $status  状态
     */
    $auth->addGroups($title, $status);

     /**
     * 改变权限组状态
     * @param $group_id  用户组ID|必填
     * @param int $status  状态|0:禁用  1：启用
     */
     $auth->changeGroupsStatus($group_id,$status);

     /**
     * 为组设置权限
     * @param $group_id 用户组ID
     * @param $rules  权限节点ID ， int | array
     */
     $auth->setAuthOfGroup($group_id, $rules);

     /**
     * 删除用户组的权限
     * @param $groups_id  用户组ID 支持数组
     * @param $rules    规格ID  支持数组
     */
    $auth->delRulesByGroup($groups_id, $rules);

     /**
     * 按用户查询用户组
     * @param $uid
     */
    $auth->getGroupsByUid($uid);

     /**
     * 按用户组查询用户
     * @param $uid
     */
    $auth->getUsersByGroup($group_id);

     /**
     * 为用户添加用户组
     * @param $uid
     * @param $groups_id  int|array
     */
    $auth->addGroupsByUid($uid, $groups_id);

     /**
     * 为用户组中添加用户
     * @param $group_id
     * @param $uids int|array
     */
    $auth->addUsersByGroup($group_id, $uids);

     /**
     * 删除用户和用户组的关系
     * @param $uid   用户ID 支持数组
     * @param $groups_id   用户组ID  支持数组
     */
    $auth->delUserGroupsRelation($uids, $groups_id);

    /**
     * 按group_id是否有权限，返回所有权限节点
     * @param $group_id
     *返回数据结构
        [
            [
                "rid"      => NULL
                "group_id" => NULL（为空说明该组对此节点没有权限,有权限将会显示相应的group_id值）
                "id"       =>    节点ID
                "name"     =>  节点名
                "title"    => 标题
                "type"     =>  类型  0普通节点  1菜单节点
                "pid"      =>   父级ID
                "status"   => 状态 是否禁用
                "icon'     => 图标
                "child"    => 子节点
            ],
            ...
        ]
     */
    $auth->getAuthRulesByGroup($group_id);

    /**
     * 按用户查询所有该用户有权限的菜单节点
     * @param $uid 用户ID
     * @return array
     */
    $auth->getAuthMenuByUser($uid)






```






