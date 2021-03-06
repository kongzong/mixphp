<?php

namespace apps\daemon\commands;

use mix\console\ExitCode;
use mix\facades\Input;
use mix\task\TaskProcess;
use mix\task\TaskExecutor;

/**
 * 这是一个多进程守护进程的范例
 * 进程模型为：生产者消费者模型
 * 你可以自由选择是左进程当生产者还是右进程当生产者，本范例是左进程当生产者
 * @author 刘健 <coder.liu@qq.com>
 */
class MultiCommand extends BaseCommand
{

    // 初始化事件
    public function onInitialize()
    {
        parent::onInitialize(); // TODO: Change the autogenerated stub
        // 获取程序名称
        $this->programName = Input::getCommandName();
        // 设置pidfile
        $this->pidFile = "/var/run/{$this->programName}.pid";
    }

    /**
     * 获取服务
     * @return TaskExecutor
     */
    public function getTaskService()
    {
        return \Mix::createObject(
            [
                // 类路径
                'class'        => 'mix\task\TaskExecutor',
                // 左进程数
                'leftProcess'  => 1,
                // 右进程数
                'rightProcess' => 3,
                // 服务名称
                'name'         => "mix-daemon: {$this->programName}",
                // 进程队列的key
                'queueKey'     => __FILE__ . uniqid(),
            ]
        );
    }

    // 启动
    public function actionStart()
    {
        // 预处理
        if (!parent::actionStart()) {
            return ExitCode::UNSPECIFIED_ERROR;
        }
        // 启动服务
        $service = $this->getTaskService();
        $service->on('LeftStart', [$this, 'onLeftStart']);
        $service->on('RightStart', [$this, 'onRightStart']);
        $service->start();
        // 返回退出码
        return ExitCode::OK;
    }

    // 左进程启动事件回调函数
    public function onLeftStart(TaskProcess $worker, $index)
    {
        // 模型内使用长连接版本的数据库组件，这样组件会自动帮你维护连接不断线
        $queueModel = new \apps\common\models\QueueModel();
        // 循环执行任务
        for ($j = 0; $j < 16000; $j++) {
            $worker->checkMaster(TaskProcess::PRODUCER);
            // 从消息队列中间件取出一条消息
            $msg = $queueModel->pop();
            // 将消息推送给消费者进程去处理，push有长度限制：https://wiki.swoole.com/wiki/page/290.html
            $worker->push(serialize($msg));
        }
    }

    // 右进程启动事件回调函数
    public function onRightStart(TaskProcess $worker, $index)
    {
        // 循环执行任务
        for ($j = 0; $j < 16000; $j++) {
            $worker->checkMaster();
            // 从进程队列中抢占一条消息
            $msg = $worker->pop();
            $msg = unserialize($msg);
            if (!empty($msg)) {
                // 处理消息，比如：发送短信、发送邮件、微信推送
                // ...
            }
        }
    }

}
