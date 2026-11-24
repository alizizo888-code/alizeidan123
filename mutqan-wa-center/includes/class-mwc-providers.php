<?php
if (!defined('ABSPATH')) exit;

/**
 * طبقة الربط: كل رقم ليه "مزوّد" — meta (واتساب الرسمي Cloud API) أو evolution (ربط QR على السيرفر).
 * الواجهة واحدة، والمزوّد بيتبدّل من الإعدادات من غير ما المحادثات تضيع.
 */
abstract class MWC_Provider {
    protected $acc; protected $cfg;
    function __construct($acc) { $this->acc = $acc; $this->cfg = $acc['config'] ?? []; }

    static function make($acc) {
        return ($acc['provider'] ?? '') === 'meta' ? new MWC_Meta($acc) : new MWC_Evolution($acc);
    }

    /** @return array ['ok'=>bool,'ext_id'=>string,'error'=>string] */
    abstract function send($to, $msg);
    function mark_read($to, $ext_ids) { return true; }
    function profile($to) { return []; }
    function connect() { return ['ok' => false, 'error' => 'غير مدعوم']; }
    function state() { return 'unknown'; }
    /** «مسح عند الجميع» لرسالة بعتناها. Meta الرسمي مالوش الخاصية دي. */
    function revoke($to, $ext_id) { return ['ok' => false, 'error' => 'غير مدعوم على الرقم ده']; }

    protected function http($method, $url, $headers = [], $body = null, $timeout = 30) {
        $args = ['method' => $method, 'headers' => $headers, 'timeout' => $timeout];
        if ($body !== null) $args['body'] = is_array($body) ? wp_json_encode($body) : $body;
        if (is_array($body)) $args['headers']['Content-Type'] = 'application/json';
        $r = wp_remote_request($url, $args);
        if (is_wp_error($r)) return ['code' => 0, 'body' => null, 'raw' => '', 'error' => $r->get_error_message()];
        $raw = wp_remote_retrieve_body($r);
        return ['code' => (int) wp_remote_retrieve_response_code($r), 'body' => json_decode($raw, true), 'raw' => $raw,
                'type' => wp_remote_retrieve_header($r, 'content-type'), 'error' => ''];
    }

    protected function local_file($url) { return MWC_Store::url_to_path($url); }
}

/* ===================== Meta WhatsApp Cloud API (الرسمي) ===================== */
class MWC_Meta extends MWC_Provider {
    private function base() { return 'https://graph.facebook.com/' . ($this->cfg['api_version'] ?? 'v21.0') . '/'; }
    private function auth() { return ['Authorization' => 'Bearer ' . ($this->cfg['token'] ?? '')]; }
    private function pnid() { return $this->cfg['phone_number_id'] ?? ''; }

    private function upload_media($url, $mime) {
        $path = $this->local_file($url);
        if (!$path || !file_exists($path)) return ['error' => 'الملف غير موجود على الموقع'];
        $boundary = wp_generate_password(24, false);
        $mime = trim(explode(';', $mime)[0]);
        $body = "--$boundary\r\nContent-Disposition: form-data; name=\"messaging_product\"\r\n\r\nwhatsapp\r\n"
              . "--$boundary\r\nContent-Disposition: form-data; name=\"type\"\r\n\r\n$mime\r\n"
              . "--$boundary\r\nContent-Disposition: form-data; name=\"file\"; filename=\"" . basename($path) . "\"\r\nContent-Type: $mime\r\n\r\n"
              . file_get_contents($path) . "\r\n--$boundary--\r\n";
        $r = $this->http('POST', $this->base() . $this->pnid() . '/media',
            $this->auth() + ['Content-Type' => 'multipart/form-data; boundary=' . $boundary], $body, 120);
        if (!empty($r['body']['id'])) return ['id' => $r['body']['id']];
        return ['error' => $r['body']['error']['message'] ?? ($r['error'] ?: 'فشل رفع الملف لـ Meta')];
    }

    function send($to, $m) {
        $p = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $to];
        if (!empty($m['reply_to'])) $p['context'] = ['message_id' => $m['reply_to']];
        $type = $m['type'];
        if ($type === 'text') {
            $p['type'] = 'text'; $p['text'] = ['body' => $m['body'], 'preview_url' => true];
        } elseif ($type === 'template') {
            $tpl = $m['template'] ?? [];
            $p['type'] = 'template';
            $p['template'] = ['name' => $tpl['name'] ?? 'hello_world', 'language' => ['code' => $tpl['lang'] ?? 'ar']];
            if (!empty($tpl['params'])) $p['template']['components'] = [['type' => 'body',
                'parameters' => array_map(function ($v) { return ['type' => 'text', 'text' => (string) $v]; }, (array) $tpl['params'])]];
        } elseif ($type === 'location') {
            $loc = json_decode($m['body'], true) ?: [];
            $p['type'] = 'location'; $p['location'] = ['latitude' => $loc['lat'] ?? 0, 'longitude' => $loc['lng'] ?? 0, 'name' => $loc['name'] ?? '', 'address' => $loc['address'] ?? ''];
        } else {
            $up = $this->upload_media($m['media_url'], $m['mime']);
            if (!empty($up['error'])) return ['ok' => false, 'error' => $up['error']];
            $wt = ['image' => 'image', 'video' => 'video', 'audio' => 'audio', 'voice' => 'audio', 'document' => 'document', 'sticker' => 'sticker'][$type] ?? 'document';
            $obj = ['id' => $up['id']];
            if (in_array($wt, ['image', 'video', 'document'], true) && !empty($m['body'])) $obj['caption'] = $m['body'];
            if ($wt === 'document') $obj['filename'] = $m['file_name'] ?: 'file';
            if ($type === 'voice') $obj['voice'] = true; // ogg/opus بيظهر عند العميل كرسالة صوتية
            $p['type'] = $wt; $p[$wt] = $obj;
        }
        $r = $this->http('POST', $this->base() . $this->pnid() . '/messages', $this->auth(), $p);
        if (!empty($r['body']['messages'][0]['id'])) return ['ok' => true, 'ext_id' => $r['body']['messages'][0]['id']];
        $err = $r['body']['error']['message'] ?? ($r['error'] ?: 'HTTP ' . $r['code']);
        if (($r['body']['error']['code'] ?? 0) == 131047 || stripos($err, '24') !== false && stripos($err, 'hour') !== false)
            $err = 'مرت 24 ساعة على آخر رسالة من العميل — لازم تبعت قالب (Template) معتمد من Meta';
        if (isset($p['voice']) || (isset($p['audio']['voice']))) {
            // لو Meta رفضت الحقل voice نعيد المحاولة من غيره
            if (stripos($err, 'voice') !== false) { unset($p['audio']['voice']);
                $r = $this->http('POST', $this->base() . $this->pnid() . '/messages', $this->auth(), $p);
                if (!empty($r['body']['messages'][0]['id'])) return ['ok' => true, 'ext_id' => $r['body']['messages'][0]['id']]; }
        }
        return ['ok' => false, 'error' => $err];
    }

    function mark_read($to, $ext_ids) {
        $last = end($ext_ids);
        if (!$last) return true;
        $this->http('POST', $this->base() . $this->pnid() . '/messages', $this->auth(),
            ['messaging_product' => 'whatsapp', 'status' => 'read', 'message_id' => $last], 10);
        return true;
    }

    /** تنزيل ميديا جاية من Meta وحفظها على موقعك */
    function download($media_id) {
        $r = $this->http('GET', $this->base() . $media_id, $this->auth());
        $url = $r['body']['url'] ?? '';
        if (!$url) return null;
        $f = $this->http('GET', $url, $this->auth(), null, 120);
        if ($f['code'] !== 200 || $f['raw'] === '') return null;
        $mime = $r['body']['mime_type'] ?? ($f['type'] ?: 'application/octet-stream');
        return ['url' => MWC_Store::save_media_bytes($f['raw'], $mime), 'mime' => $mime];
    }

    function state() { return (!empty($this->cfg['token']) && $this->pnid()) ? 'open' : 'not_configured'; }

    function connect() {
        $r = $this->http('GET', $this->base() . $this->pnid() . '?fields=display_phone_number,verified_name,quality_rating', $this->auth());
        if (!empty($r['body']['display_phone_number']))
            return ['ok' => true, 'state' => 'open', 'info' => $r['body']];
        return ['ok' => false, 'state' => 'error', 'error' => $r['body']['error']['message'] ?? ($r['error'] ?: 'تأكد من التوكن ورقم Phone Number ID')];
    }
}

/* ===================== Evolution API (ربط QR على السيرفر — كل مميزات واتساب ويب) ===================== */
class MWC_Evolution extends MWC_Provider {
    function __construct($acc) {
        parent::__construct($acc);
        // السيرفر الافتراضي (Evolution على الـVPS) لو الرقم مالوش رابط خاص
        $def = get_option('mwc_evo_default');
        if (is_array($def)) { if (empty($this->cfg['base_url'])) $this->cfg['base_url'] = $def['base_url'] ?? ''; if (empty($this->cfg['apikey'])) $this->cfg['apikey'] = $def['apikey'] ?? ''; }
    }
    private function base() { return rtrim($this->cfg['base_url'] ?? '', '/') . '/'; }
    private function inst() { return rawurlencode($this->cfg['instance'] ?? ('mwc' . $this->acc['id'])); }
    private function h() { return ['apikey' => $this->cfg['apikey'] ?? '']; }
    private function call($method, $path, $body = null, $timeout = 30) {
        return $this->http($method, $this->base() . $path, $this->h(), $body, $timeout);
    }

    static function jid_number($to) {
        if (strpos($to, 'lid:') === 0) return substr($to, 4) . '@lid';
        return $to;
    }

    private function media_payload($m) {
        $path = $this->local_file($m['media_url']);
        // الإرسال كـ base64 عشان يشتغل حتى لو السيرفر مش شايف الموقع
        return ($path && file_exists($path) && filesize($path) < 40 * 1024 * 1024) ? base64_encode(file_get_contents($path)) : $m['media_url'];
    }

    /** مسح رسالة عند الجميع (Evolution: DELETE chat/deleteMessageForEveryone) — لازم قبل ما مهلة واتساب تخلص (حوالي يومين). */
    function revoke($to, $ext_id) {
        $num = self::jid_number($to);
        $jid = strpos($num, '@') !== false ? $num : preg_replace('/\D/', '', $num) . '@s.whatsapp.net';
        $r = $this->call('DELETE', 'chat/deleteMessageForEveryone/' . $this->inst(), ['id' => (string) $ext_id, 'remoteJid' => $jid, 'fromMe' => true]);
        if ($r['code'] >= 200 && $r['code'] < 300) return ['ok' => true];
        $err = $r['body']['response']['message'] ?? ($r['body']['message'] ?? ($r['error'] ?: 'HTTP ' . $r['code']));
        return ['ok' => false, 'error' => is_array($err) ? wp_json_encode($err, JSON_UNESCAPED_UNICODE) : (string) $err];
    }

    function send($to, $m) {
        $num = self::jid_number($to);
        $type = $m['type'];
        $quoted = !empty($m['reply_to']) ? ['quoted' => ['key' => ['id' => $m['reply_to']]]] : [];
        if ($type === 'text' || $type === 'template') {
            // a link shows its card (picture + title) under the text — our order page link (2026-11-24)
            $r = $this->call('POST', 'message/sendText/' . $this->inst(), ['number' => $num, 'text' => $m['body'], 'linkPreview' => (bool) preg_match('#https?://#', (string) $m['body'])] + $quoted);
        } elseif ($type === 'voice') {
            $r = $this->call('POST', 'message/sendWhatsAppAudio/' . $this->inst(), ['number' => $num, 'audio' => $this->media_payload($m)] + $quoted, 120);
        } elseif ($type === 'location') {
            $loc = json_decode($m['body'], true) ?: [];
            $r = $this->call('POST', 'message/sendLocation/' . $this->inst(), ['number' => $num, 'name' => $loc['name'] ?? '', 'address' => $loc['address'] ?? '',
                'latitude' => (float) ($loc['lat'] ?? 0), 'longitude' => (float) ($loc['lng'] ?? 0)]);
        } else {
            $mt = in_array($type, ['image', 'video', 'document'], true) ? $type : ($type === 'audio' ? 'audio' : 'document');
            if ($mt === 'audio') $mt = 'document'; // ملف صوتي عادي (مش ريكورد) يتبعت كملف
            $r = $this->call('POST', 'message/sendMedia/' . $this->inst(), [
                'number' => $num, 'mediatype' => $mt, 'mimetype' => trim(explode(';', $m['mime'])[0]),
                'caption' => $m['body'] ?? '', 'media' => $this->media_payload($m), 'fileName' => $m['file_name'] ?: basename($m['media_url']),
            ] + $quoted, 120);
        }
        $id = $r['body']['key']['id'] ?? ($r['body']['data']['key']['id'] ?? '');
        if ($id) return ['ok' => true, 'ext_id' => $id];
        $err = $r['body']['response']['message'] ?? ($r['body']['message'] ?? ($r['error'] ?: 'HTTP ' . $r['code']));
        if (is_array($err)) $err = wp_json_encode($err, JSON_UNESCAPED_UNICODE);
        return ['ok' => false, 'error' => (string) $err];
    }

    function mark_read($to, $ext_ids) {
        if (!$ext_ids) return true;
        $jid = strpos($to, 'lid:') === 0 ? substr($to, 4) . '@lid' : $to . '@s.whatsapp.net';
        $this->call('POST', 'chat/markMessageAsRead/' . $this->inst(), ['readMessages' => array_map(function ($id) use ($jid) {
            return ['remoteJid' => $jid, 'fromMe' => false, 'id' => $id]; }, array_slice($ext_ids, -20))], 10);
        return true;
    }

    /** صورة البروفايل + النبذة (About) زي الواتساب بالظبط */
    function profile($to) {
        $num = self::jid_number($to);
        $out = [];
        $pic = $this->call('POST', 'chat/fetchProfilePictureUrl/' . $this->inst(), ['number' => $num], 15);
        $u = $pic['body']['profilePictureUrl'] ?? '';
        if ($u) {
            $img = $this->http('GET', $u, [], null, 30);
            if ($img['code'] === 200 && $img['raw'] !== '') $out['avatar'] = MWC_Store::save_media_bytes($img['raw'], 'image/jpeg');
        }
        $pr = $this->call('POST', 'chat/fetchProfile/' . $this->inst(), ['number' => $num], 15);
        $b = $pr['body'] ?? [];
        $about = $b['status']['status'] ?? ($b['status'] ?? ($b['description'] ?? ''));
        if (is_string($about) && $about !== '') $out['about'] = $about;
        if (!empty($b['name']) && is_string($b['name'])) $out['push_name'] = $b['name'];
        if (!empty($b['isBusiness'])) $out['is_business'] = 1;
        return $out;
    }

    function get_media_base64($ext_id) {
        $r = $this->call('POST', 'chat/getBase64FromMediaMessage/' . $this->inst(), ['message' => ['key' => ['id' => $ext_id]], 'convertToMp4' => false], 120);
        return !empty($r['body']['base64']) ? ['b64' => $r['body']['base64'], 'mime' => $r['body']['mimetype'] ?? ''] : null;
    }

    function state() {
        $r = $this->call('GET', 'instance/connectionState/' . $this->inst(), null, 10);
        return $r['body']['instance']['state'] ?? ($r['body']['state'] ?? 'unknown');
    }

    /** ينشئ الرقم على السيرفر لو مش موجود، يربط الـwebhook، ويرجّع QR */
    function connect() {
        $hook = add_query_arg('s', get_option('mwc_webhook_secret'), rest_url(MWC_NS . '/webhook/evolution'));
        $events = ['MESSAGES_UPSERT', 'MESSAGES_UPDATE', 'MESSAGES_DELETE', 'MESSAGES_EDITED', 'CONNECTION_UPDATE', 'QRCODE_UPDATED', 'CONTACTS_UPSERT', 'SEND_MESSAGE'];
        $st = $this->state();
        if ($st === 'unknown' || $st === '') {
            $this->call('POST', 'instance/create', ['instanceName' => $this->cfg['instance'] ?? ('mwc' . $this->acc['id']),
                'integration' => 'WHATSAPP-BAILEYS', 'qrcode' => true,
                'webhook' => ['url' => $hook, 'byEvents' => false, 'base64' => true, 'events' => $events]], 30);
        }
        $this->call('POST', 'webhook/set/' . $this->inst(), ['webhook' => ['enabled' => true, 'url' => $hook, 'byEvents' => false, 'base64' => true, 'events' => $events]], 15);
        $st = $this->state();
        if ($st === 'open') return ['ok' => true, 'state' => 'open'];
        $q = $this->call('GET', 'instance/connect/' . $this->inst(), null, 30);
        $b = $q['body'] ?? [];
        $qr = $b['base64'] ?? ($b['qrcode']['base64'] ?? '');
        if ($qr && strpos($qr, 'data:') !== 0) $qr = 'data:image/png;base64,' . $qr;
        if (!$qr && !$b) return ['ok' => false, 'state' => 'error', 'error' => $q['error'] ?: 'السيرفر مش بيرد — تأكد من الرابط والـ API Key'];
        return ['ok' => true, 'state' => $st ?: 'connecting', 'qr' => $qr, 'pairing' => $b['pairingCode'] ?? ''];
    }

    function logout() { $this->call('DELETE', 'instance/logout/' . $this->inst(), null, 15); }
}
