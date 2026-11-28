<?php
// One assistant, powers by role (owner, 2026-11-20): the owner and operations steer prices, intake, technicians and
// customers; a technician moves his own jobs; the customer bot obeys the owner's intake order through the house rules.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/wpshim.php';
require dirname(__DIR__, 2) . '/oxygen-ac-automation/includes/mutqan/class-mutqan-command.php';
Mutqan_Store::maybe_install();
$ok = true;
$t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . 'command: ' . $n . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$sys = ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user'];
$sh = ['groupId' => 'main', 'visibleTo' => 'group', 'writableBy' => 'admins'];
$owner = ['id' => 'u-owner', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user', 'name' => 'علي'];
$ops = ['id' => 'u-ops', 'role' => 'operations', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user', 'name' => 'العمليات'];
$A = 'Mutqan_Agent';
$names = function ($p) use ($A) { return array_column($A::tools_for($p), 'name'); };
$tech = Mutqan_Auth::make_member('فني تجربة', '0531234567', 'technician');
$tp = ['id' => $tech['id'], 'role' => 'technician', 'powers' => ['read', 'write'], 'kind' => 'user', 'name' => 'فني تجربة'];
$other = Mutqan_Auth::make_member('فني تاني', '0539876543', 'technician');
Mutqan_Store::upsert('tech_profiles', ['userId' => $tech['id'], 'displayName' => 'فني تجربة', 'active' => true], $tech['id'], $sh, $sys);
$pushes = function ($ev) use ($sys) { return array_values(array_filter(Mutqan_Store::select('_push', [], ['limit' => 300], $sys), function ($m) use ($ev) { return ($m['event'] ?? '') === $ev; })); };

/* ---------------------------------------------------------------- who has which tools */
$o = $names($owner); $op = $names($ops); $te = $names($tp);
$t('owner: prices, intake, technicians, customers', !array_diff(['prices_adjust', 'intake_set', 'order_hold', 'tech_notify', 'tech_account', 'tech_role', 'customer_notify', 'order_assign', 'sales_teach'], $o), $o);
$t('operations: day-to-day control but not prices or roles', in_array('intake_set', $op, true) && in_array('order_hold', $op, true) && !in_array('prices_adjust', $op, true) && !in_array('tech_role', $op, true) && !in_array('dns_set', $op, true), $op);
$t('technician: only his own jobs', $te == ['my_jobs', 'job_status', 'job_problem'], $te);
$denied = ''; try { $A::run_tool('prices_adjust', ['percent' => 10], $ops); } catch (Exception $e) { $denied = $e->getMessage(); }
$t('operations asking to change prices is refused', $denied !== '', $denied);
$denied = ''; try { $A::run_tool('orders_list', [], $tp); } catch (Exception $e) { $denied = $e->getMessage(); }
$t('a technician cannot list all orders', $denied !== '', $denied);
$t('the assistant answers technicians now (not only the office)', (function () use ($A, $tp) { try { $A::handle(['messages' => []], $tp); } catch (Mutqan_Error $e) { return $e->slug !== 'forbidden'; } return true; })());

/* ---------------------------------------------------------------- intake → the customer bot */
$t('intake starts open, the house rules say nothing extra', Mutqan_Command::intake()['mode'] === 'open' && strpos(Mutqan_AI::house_rules(), 'أمر من الإدارة') === false);
$A::run_tool('intake_set', ['mode' => 'tomorrow', 'hours' => 5, 'note' => 'ضغط شغل'], $ops);
$hr = Mutqan_AI::house_rules();
$t('«السوق زحمة»: every customer conversation now books for tomorrow', strpos($hr, 'أمر من الإدارة') !== false && strpos($hr, 'بكره الصبح') !== false && strpos($hr, 'ضغط شغل') !== false, mb_substr($hr, -300));
$t('…the office is told', count($pushes('intake')) >= 1);
$t('pausing intake waits for «تنفيذ»', $A::needs_confirm('intake_set', ['mode' => 'paused']) && !$A::needs_confirm('intake_set', ['mode' => 'tomorrow']));
$A::run_tool('intake_set', ['mode' => 'paused'], $owner);
$t('paused: the bot apologises and takes details instead of an order', strpos(Mutqan_AI::house_rules(), 'موقوف مؤقتًا') !== false);
update_option('mutqan_intake', ['mode' => 'tomorrow', 'note' => '', 'until' => gmdate('c', time() - 60)]);
$t('an intake order with a time limit ends by itself', Mutqan_Command::intake()['mode'] === 'open');

/* ---------------------------------------------------------------- prices */
$before = Mutqan_Policy::ac_table();
$r = $A::run_tool('prices_adjust', ['percent' => 10, 'maxDiscountPct' => 5], $owner);
$after = Mutqan_Policy::ac_table();
$t('prices up 10%, rounded to 5 riyals, in the table the app and the bot read', $after['split1'] == 110 && $after['split3'] == 220 && $after['win1'] == 65 && $after['maxDiscountPct'] == 5, $after);
$t('the bot quotes the new price', strpos(Mutqan_AI::house_rules(), '1 = 110') !== false);
$t('price changes wait for «تنفيذ»', $A::needs_confirm('prices_adjust', ['percent' => 10]));
$A::run_tool('prices_revert', [], $owner);
$t('and come back exactly', Mutqan_Policy::ac_table()['split1'] == $before['split1'] && Mutqan_Policy::ac_table()['maxDiscountPct'] == $before['maxDiscountPct']);

/* ---------------------------------------------------------------- technicians */
$A::run_tool('tech_notify', ['techId' => $tech['id'], 'title' => 'تنبيه', 'body' => 'روح العزيزية'], $ops);
$n = $pushes('admin_note');
$t('a notification reaches that technician', count($n) === 1 && ($n[0]['target']['user'] ?? '') === $tech['id'], $n);
$A::run_tool('tech_account', ['techId' => $tech['id'], 'action' => 'suspend', 'reason' => 'غياب'], $owner);
$u = Mutqan_Auth::user_row($tech['id']);
$t('suspend: account disabled and out of the dispatch pool', ($u['status'] ?? '') === 'disabled' && Mutqan_Store::peek_doc('tech_profiles', $tech['id'])['active'] === false, $u);
$t('suspending waits for «تنفيذ», activating does not', $A::needs_confirm('tech_account', ['action' => 'suspend']) && !$A::needs_confirm('tech_account', ['action' => 'activate']));
$A::run_tool('tech_account', ['techId' => $tech['id'], 'action' => 'activate'], $owner);
$u = Mutqan_Auth::user_row($tech['id']);
$t('activate: back with his role', ($u['status'] ?? '') === 'active' && ($u['role'] ?? '') === 'technician', $u);

/* ---------------------------------------------------------------- orders: send / stop / move */
$ord = Mutqan_Store::insert('orders', ['status' => 'assigned', 'specialtyId' => 'ac', 'clientName' => 'عميل', 'clientPhone' => '0500000009', 'clientId' => 'u-cust', 'technicianId' => $tech['id'], 'technicianName' => 'فني تجربة'], null, $sh, $sys);
$A::run_tool('order_hold', ['id' => $ord['id'], 'action' => 'hold', 'reason' => 'العميل طلب يستنى'], $ops);
$t('hold: the job is stopped and the technician hears it', !empty(Mutqan_Store::get_raw('orders', $ord['id'])['held']) && count($pushes('order_hold')) === 1);
$err = ''; try { $A::run_tool('job_status', ['id' => $ord['id'], 'status' => 'en_route'], $tp); } catch (Exception $e) { $err = $e->getMessage(); }
$t('a held job cannot be moved by the technician', $err !== '', $err);
$A::run_tool('order_hold', ['id' => $ord['id'], 'action' => 'resume'], $ops);
$r = $A::run_tool('job_status', ['id' => $ord['id'], 'status' => 'en_route'], $tp);
$t('resume, then the technician sets «في الطريق» from the assistant', ($r['status'] ?? '') === 'en_route', $r);
$err = ''; try { $A::run_tool('job_status', ['id' => $ord['id'], 'status' => 'arrived'], ['id' => $other['id'], 'role' => 'technician', 'kind' => 'user', 'powers' => ['read', 'write']]); } catch (Exception $e) { $err = $e->getMessage(); }
$t('another technician cannot touch it', $err !== '', $err);
$jobs = $A::run_tool('my_jobs', [], $tp);
$t('«شغلي» lists his open job', count($jobs) === 1 && $jobs[0]['id'] === $ord['id'], $jobs);
$A::run_tool('job_problem', ['id' => $ord['id'], 'text' => 'محتاج كمبروسر'], $tp);
$msgs = Mutqan_Store::select('order_msgs', ['orderId' => $ord['id']], ['limit' => 20], $sys);
$prob = array_values(array_filter($msgs, function ($m) { return strpos($m['text'] ?? '', 'كمبروسر') !== false; }));
$t('a problem reaches the office at once, on the technician channel (not the customer\'s)', count($pushes('tech_problem')) >= 1 && $prob && ($prob[0]['channel'] ?? '') === 'tech' && empty($prob[0]['clientId']), $prob);
$A::run_tool('customer_notify', ['orderId' => $ord['id'], 'text' => 'الفني في الطريق خلال ربع ساعة'], $ops);
$cm = array_values(array_filter(Mutqan_Store::select('order_msgs', ['orderId' => $ord['id']], ['limit' => 20], $sys), function ($m) { return strpos($m['text'] ?? '', 'ربع ساعة') !== false; }));
$t('a message to the customer lands on his order page and his phone', $cm && ($cm[0]['clientId'] ?? '') === 'u-cust' && count($pushes('order_msg')) === 1, $cm);
$A::run_tool('order_hold', ['id' => $ord['id'], 'action' => 'tomorrow', 'reason' => 'ضغط'], $owner);
$o2 = Mutqan_Store::get_raw('orders', $ord['id']);
$t('«tomorrow»: appointment moved to tomorrow morning, customer told', strpos((string) $o2['appointmentAt'], (new DateTime('tomorrow', new DateTimeZone('Asia/Riyadh')))->format('Y-m-d')) === 0 && count($pushes('order_status')) >= 1, $o2['appointmentAt'] ?? null);
$A::run_tool('order_hold', ['id' => $ord['id'], 'action' => 'unassign'], $ops);
$o3 = Mutqan_Store::get_raw('orders', $ord['id']);
$t('unassign: back to new, no technician', ($o3['status'] ?? '') === 'new' && empty($o3['technicianId']), $o3);

echo $ok ? "ALL PASS command\n" : "FAILED command\n";
exit($ok ? 0 : 1);
