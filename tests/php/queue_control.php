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
        $control->change('praxis','202',true);
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
