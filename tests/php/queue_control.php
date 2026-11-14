<?php
declare(strict_types=1);

use Kienzlefon\QueueControl;

test('Queue-Zustand wird pro Warteschlange getrennt aus AMI ermittelt', function () {
    $t = config()['telephony'];
    $s = new Telephony($t);
    $s->beginSync();
    $s->finishSync([], [
        ['Event'=>'QueueParams','Queue'=>'praxis'],
        ['Event'=>'QueueParams','Queue'=>'kienzlefon-sonder'],
        ['Event'=>'QueueMember','Queue'=>'praxis','Interface'=>'Local/201@kienzlefon-queue-member','Paused'=>'1'],
        ['Event'=>'QueueMember','Queue'=>'kienzlefon-sonder','Interface'=>'Local/201@kienzlefon-queue-member','Paused'=>'0'],
    ]);
    eq($s->queuePresent('praxis'), true);
    eq($s->queueMemberStates('praxis')['201'], false);
    eq($s->queueMemberStates('kienzlefon-sonder')['201'], true);
});

test('Queue-Steuerung: zuschalten, letzte Nebenstelle schützen und persistent speichern', function () {
    $t = config()['telephony'];
    $t['extensions'] = ['201'=>'Backoffice','202'=>'Zimmer 6','300'=>'Sprechzimmer'];
    $s = new Telephony($t);
    $s->beginSync();
    $s->finishSync([], [
        ['Event'=>'QueueParams','Queue'=>'praxis'],
        ['Event'=>'QueueParams','Queue'=>'kienzlefon-sonder'],
        ['Event'=>'QueueMember','Queue'=>'praxis','Interface'=>'Local/201@kienzlefon-queue-member','Paused'=>'0'],
        ['Event'=>'QueueMember','Queue'=>'kienzlefon-sonder','Interface'=>'Local/201@kienzlefon-queue-member','Paused'=>'0'],
    ]);
    $root = sys_get_temp_dir() . '/kf-queues-' . bin2hex(random_bytes(6));
    mkdir($root);
    $path = $root . '/queue-selection.json';
    $cfg = ['enabled'=>true,'state_file'=>$path,'default_practice'=>['201'],'default_special'=>['201']];
    $commands = [];
    $action = function (string $name, array $fields) use (&$commands) {
        $commands[] = [$name,$fields];
        return \React\Promise\resolve(['Response'=>'Success']);
    };
    try {
        $control = new QueueControl($cfg,$t['extensions'],$s,$action);
        eq($control->view()['ready'],true);
        $control->change('praxis','202',true)->then(function ($result) { eq($result['ready'], true); });
        eq($commands[0][0],'QueueAdd');
        eq($commands[0][1]['Interface'],'Local/202@kienzlefon-queue-member');
        eq($commands[0][1]['StateInterface'],'PJSIP/202');
        eq($control->view()['selection']['praxis']['202'],true);
        $control->change('praxis','201',false);
        eq($commands[1][0],'QueuePause');
        eq($commands[1][1]['Paused'],'true');
        eq($control->view()['selection']['praxis']['201'],false);
        $rejected = false;
        $control->change('praxis','202',false)->then(null, function () use (&$rejected) { $rejected = true; });
        eq($rejected,true);
        eq($control->view()['selection']['praxis']['202'],true);
        eq(json_decode(file_get_contents($path),true)['praxis']['202'],true);
        $again = new QueueControl($cfg,$t['extensions'],$s,$action);
        eq($again->view()['selection']['praxis']['202'],true);
        $again->restore();
        eq($commands[2][0],'QueuePause');
        eq($commands[3][0],'QueueAdd');
        $s->disconnect();
        eq($again->view()['ready'],false);
        $failed = false;
        $again->change('praxis','201',true)->then(null,function () use (&$failed) { $failed=true; });
        eq($failed,true);
    } finally {
        @unlink($path);
        rmdir($root);
    }
});

test('Queue-Konfiguration: ungültige Vorgaben und Statuspfade abweisen', function () {
    $c = config();
    $c['queue_control']['enabled'] = true;
    $c['queue_control']['default_special'] = ['999'];
    rejects(fn() => Config::validate($c));
    $c = config();
    $c['queue_control']['state_file'] = '/tmp/untrusted.json';
    rejects(fn() => Config::validate($c));
});

test('Sieben lokale Nebenstellen: Sprechzimmer dynamisch zur Praxisqueue hinzuschalten', function () {
    $t = config()['telephony'];
    $t['extensions'] = [
        '201'=>'Backoffice', '202'=>'Zimmer 6', '203'=>'Tresen rechts',
        '204'=>'Tresen links', '301'=>'Sprechzimmer 1',
        '302'=>'Sprechzimmer 2', '303'=>'Sprechzimmer 3',
    ];
    $s = new Telephony($t);
    $frames = [
        ['Event'=>'QueueParams','Queue'=>'praxis'],
        ['Event'=>'QueueParams','Queue'=>'kienzlefon-sonder'],
    ];
    foreach (['201','202','203','204'] as $ext) {
        $frames[] = ['Event'=>'QueueMember','Queue'=>'praxis',
                     'Interface'=>"Local/$ext@kienzlefon-queue-member",'Paused'=>'0'];
    }
    foreach (array_keys($t['extensions']) as $ext) {
        $frames[] = ['Event'=>'QueueMember','Queue'=>'kienzlefon-sonder',
                     'Interface'=>"Local/$ext@kienzlefon-queue-member",'Paused'=>'0'];
    }
    $s->beginSync();
    $s->finishSync([], $frames);
    $directory = sys_get_temp_dir() . '/kf-seven-' . bin2hex(random_bytes(6));
    mkdir($directory);
    $stateFile = $directory . '/selection.json';
    $cfg = [
        'enabled'=>true, 'state_file'=>$stateFile,
        'default_practice'=>['201','202','203','204'],
        'default_special'=>array_keys($t['extensions']),
    ];
    $commands = [];
    $action = function (string $name, array $fields) use (&$commands) {
        $commands[] = [$name,$fields];
        return \React\Promise\resolve(['Response'=>'Success']);
    };
    try {
        $controller = new QueueControl($cfg, $t['extensions'], $s, $action);
        eq($controller->view()['selection']['praxis']['301'], false);
        eq($controller->view()['selection']['kienzlefon-sonder']['301'], true);
        $controller->change('praxis', '303', true);
        eq($commands[0][0], 'QueueAdd');
        eq($commands[0][1]['Queue'], 'praxis');
        eq($commands[0][1]['Interface'], 'Local/303@kienzlefon-queue-member');
        eq($commands[0][1]['Penalty'], '3');
        eq($commands[0][1]['StateInterface'], 'PJSIP/303');
        $controller->change('kienzlefon-sonder', '302', false);
        eq($commands[1][0], 'QueuePause');
        eq($commands[1][1]['Interface'], 'Local/302@kienzlefon-queue-member');
        eq($commands[1][1]['Paused'], 'true');
        eq(json_decode(file_get_contents($stateFile), true)['praxis']['303'], true);
        eq(json_decode(file_get_contents($stateFile), true)['kienzlefon-sonder']['302'], false);
        $afterRestart = new QueueControl($cfg, $t['extensions'], $s, $action);
        eq($afterRestart->view()['selection']['praxis']['303'], true);
        eq($afterRestart->view()['selection']['kienzlefon-sonder']['302'], false);
        $afterRestart->restore();
        eq($commands[2][0], 'QueueAdd');
        eq($commands[2][1]['Interface'], 'Local/303@kienzlefon-queue-member');
        eq($commands[3][0], 'QueuePause');
        eq($commands[3][1]['Interface'], 'Local/302@kienzlefon-queue-member');
    } finally {
        @unlink($stateFile);
        rmdir($directory);
    }
});
