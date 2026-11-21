<?php
// API Iuran Badminton PB. SEMI: PHP + MySQL (PDO), login berbasis sesi, peran admin/anggota.
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();

class ApiError extends Exception {
    public $status;
    public function __construct($status, $msg) { parent::__construct($msg); $this->status = $status; }
}
function out($code, $data) { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function cut($s, $n) { $s = (string)$s; return function_exists('mb_substr') ? mb_substr($s, 0, $n, 'UTF-8') : substr($s, 0, $n); }
function to_int($v, $min, $max) { return max($min, min($max, (int)$v)); }
function to_date($v) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) ? $v : date('Y-m-d'); }
function clean_id($v) { return preg_match('/^[a-z0-9]{3,40}$/', (string)$v) ? (string)$v : null; }

function pdo() {
    static $pdo = null;
    if ($pdo) return $pdo;
    try {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (Exception $e) {
        out(500, ['error' => 'Tidak bisa terhubung ke database. Periksa config.php (host, nama, user, password).']);
    }
    return $pdo;
}

function ensure_schema($p) {
    $sql = [
        "CREATE TABLE IF NOT EXISTS users (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(30) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, role ENUM('admin','anggota') NOT NULL DEFAULT 'anggota', created_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS sesi (id VARCHAR(40) PRIMARY KEY, nama VARCHAR(80) NOT NULL DEFAULT '', tgl DATE NOT NULL, hk INT NOT NULL DEFAULT 3000, hh INT NOT NULL DEFAULT 2000, rounds MEDIUMTEXT NULL, updated_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS pemain (sesi_id VARCHAR(40) NOT NULL, id VARCHAR(40) NOT NULL, urut INT NOT NULL DEFAULT 0, nama VARCHAR(60) NOT NULL, hadir TINYINT(1) NOT NULL DEFAULT 1, kok INT NOT NULL DEFAULT 0, main INT NOT NULL DEFAULT 0, bayar TINYINT(1) NOT NULL DEFAULT 0, bayar_via VARCHAR(20) NULL, PRIMARY KEY (sesi_id, id), FOREIGN KEY (sesi_id) REFERENCES sesi(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS hutang (id VARCHAR(40) PRIMARY KEY, nama VARCHAR(60) NOT NULL, jumlah INT NOT NULL DEFAULT 0, ket VARCHAR(120) NOT NULL DEFAULT '', tgl DATE NOT NULL, lunas TINYINT(1) NOT NULL DEFAULT 0, tgl_lunas DATE NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS penabung (id VARCHAR(40) PRIMARY KEY, nama VARCHAR(60) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS tabungan_tx (seq BIGINT AUTO_INCREMENT PRIMARY KEY, id VARCHAR(40) NOT NULL UNIQUE, penabung_id VARCHAR(40) NOT NULL, tgl DATE NOT NULL, tipe ENUM('setor','tarik','iuran') NOT NULL, jumlah INT NOT NULL DEFAULT 0, ket VARCHAR(120) NOT NULL DEFAULT '', sesi_id VARCHAR(40) NULL, pemain_id VARCHAR(40) NULL, FOREIGN KEY (penabung_id) REFERENCES penabung(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($sql as $q) $p->exec($q);
}

function current_user($p) {
    if (empty($_SESSION['uid'])) return null;
    $st = $p->prepare('SELECT id, username, role FROM users WHERE id = ?');
    $st->execute([$_SESSION['uid']]);
    $u = $st->fetch();
    if (!$u) { $_SESSION = []; return null; }
    return ['id' => (int)$u['id'], 'username' => $u['username'], 'role' => $u['role']];
}
function public_user($u) { return $u ? ['username' => $u['username'], 'role' => $u['role']] : null; }
function norm_user($s) { return strtolower(trim((string)$s)); }
function check_account($n, $pw) {
    if (!preg_match('/^[a-z0-9_.-]{3,30}$/', $n)) throw new ApiError(400, 'Nama pengguna 3-30 karakter: huruf, angka, titik, strip, garis bawah');
    if (strlen($pw) < 6) throw new ApiError(400, 'Kata sandi minimal 6 karakter');
}

/* ---------- Baca semua data ---------- */
function load_all($p) {
    $players = [];
    foreach ($p->query('SELECT sesi_id, id, nama, hadir, kok, main, bayar, bayar_via FROM pemain ORDER BY sesi_id, urut') as $r) {
        $o = ['id' => $r['id'], 'nama' => $r['nama'], 'hadir' => (bool)$r['hadir'], 'kok' => (int)$r['kok'], 'match' => (int)$r['main'], 'bayar' => (bool)$r['bayar']];
        if ($r['bayar_via']) $o['bayarVia'] = $r['bayar_via'];
        $players[$r['sesi_id']][] = $o;
    }
    $sesi = [];
    foreach ($p->query('SELECT id, nama, tgl, hk, hh, rounds FROM sesi ORDER BY tgl DESC, updated_at DESC, id') as $s) {
        $rd = $s['rounds'] ? json_decode($s['rounds'], true) : [];
        $sesi[] = ['id' => $s['id'], 'nama' => $s['nama'], 'tgl' => $s['tgl'], 'hk' => (int)$s['hk'], 'hh' => (int)$s['hh'],
                   'players' => isset($players[$s['id']]) ? $players[$s['id']] : [], 'rounds' => is_array($rd) ? $rd : []];
    }
    $hutang = [];
    foreach ($p->query('SELECT id, nama, jumlah, ket, tgl, lunas, tgl_lunas FROM hutang ORDER BY tgl, id') as $h) {
        $o = ['id' => $h['id'], 'nama' => $h['nama'], 'jumlah' => (int)$h['jumlah'], 'ket' => $h['ket'], 'tgl' => $h['tgl'], 'lunas' => (bool)$h['lunas']];
        if ($h['tgl_lunas']) $o['tglLunas'] = $h['tgl_lunas'];
        $hutang[] = $o;
    }
    $tx = [];
    foreach ($p->query('SELECT id, penabung_id, tgl, tipe, jumlah, ket, sesi_id, pemain_id FROM tabungan_tx ORDER BY seq') as $t) {
        $o = ['id' => $t['id'], 'tgl' => $t['tgl'], 'tipe' => $t['tipe'], 'jumlah' => (int)$t['jumlah'], 'ket' => $t['ket']];
        if ($t['sesi_id']) $o['sid'] = $t['sesi_id'];
        if ($t['pemain_id']) $o['pid'] = $t['pemain_id'];
        $tx[$t['penabung_id']][] = $o;
    }
    $tab = [];
    foreach ($p->query('SELECT id, nama FROM penabung ORDER BY nama, id') as $s) {
        $tab[] = ['id' => $s['id'], 'nama' => $s['nama'], 'tx' => isset($tx[$s['id']]) ? $tx[$s['id']] : []];
    }
    return ['sesi' => $sesi, 'hutang' => $hutang, 'tabungan' => $tab];
}

/* ---------- Validasi & simpan ---------- */
function clean_rounds($r) {
    $out = [];
    foreach (array_slice(is_array($r) ? $r : [], 0, 100) as $x) {
        if (!is_array($x)) continue;
        $names = function ($a) { $o = []; foreach (array_slice(is_array($a) ? $a : [], 0, 2) as $n) $o[] = cut($n, 60); return $o; };
        $ms = [];
        foreach (array_slice(isset($x['matches']) && is_array($x['matches']) ? $x['matches'] : [], 0, 50) as $m) {
            if (is_array($m)) $ms[] = ['a' => $names(isset($m['a']) ? $m['a'] : []), 'b' => $names(isset($m['b']) ? $m['b'] : [])];
        }
        $bye = [];
        foreach (array_slice(isset($x['bye']) && is_array($x['bye']) ? $x['bye'] : [], 0, 50) as $n) $bye[] = cut($n, 60);
        $out[] = ['mode' => (isset($x['mode']) && $x['mode'] === 'tunggal') ? 'tunggal' : 'ganda', 'matches' => $ms, 'bye' => $bye, 'counted' => !empty($x['counted'])];
    }
    return $out;
}
function save_sesi($p, $s) {
    $id = is_array($s) ? clean_id(isset($s['id']) ? $s['id'] : '') : null;
    if (!$id) throw new ApiError(400, 'Catatan tidak valid');
    $rounds = json_encode(clean_rounds(isset($s['rounds']) ? $s['rounds'] : []), JSON_UNESCAPED_UNICODE);
    $p->prepare('INSERT INTO sesi (id, nama, tgl, hk, hh, rounds, updated_at) VALUES (?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE nama=VALUES(nama), tgl=VALUES(tgl), hk=VALUES(hk), hh=VALUES(hh), rounds=VALUES(rounds), updated_at=NOW()')
      ->execute([$id, cut(isset($s['nama']) ? $s['nama'] : '', 80), to_date(isset($s['tgl']) ? $s['tgl'] : ''), to_int(isset($s['hk']) ? $s['hk'] : 0, 0, 10000000), to_int(isset($s['hh']) ? $s['hh'] : 0, 0, 10000000), $rounds]);
    $p->prepare('DELETE FROM pemain WHERE sesi_id = ?')->execute([$id]);
    $ins = $p->prepare('INSERT INTO pemain (sesi_id, id, urut, nama, hadir, kok, main, bayar, bayar_via) VALUES (?,?,?,?,?,?,?,?,?)');
    $seen = [];
    foreach (array_slice(isset($s['players']) && is_array($s['players']) ? $s['players'] : [], 0, 200) as $i => $pl) {
        if (!is_array($pl)) continue;
        $pid = clean_id(isset($pl['id']) ? $pl['id'] : '');
        if (!$pid || isset($seen[$pid])) $pid = substr(bin2hex(random_bytes(6)), 0, 12);
        $seen[$pid] = 1;
        $via = (isset($pl['bayarVia']) && $pl['bayarVia'] === 'tabungan') ? 'tabungan' : null;
        $ins->execute([$id, $pid, $i, cut(isset($pl['nama']) ? $pl['nama'] : '', 60), !empty($pl['hadir']) ? 1 : 0,
            to_int(isset($pl['kok']) ? $pl['kok'] : 0, 0, 999), to_int(isset($pl['match']) ? $pl['match'] : 0, 0, 999), !empty($pl['bayar']) ? 1 : 0, $via]);
    }
}
function save_hutang($p, $h) {
    $id = is_array($h) ? clean_id(isset($h['id']) ? $h['id'] : '') : null;
    if (!$id) throw new ApiError(400, 'Hutang tidak valid');
    $nama = trim(cut(isset($h['nama']) ? $h['nama'] : '', 60));
    if ($nama === '') throw new ApiError(400, 'Nama wajib diisi');
    $lunas = !empty($h['lunas']);
    $p->prepare('INSERT INTO hutang (id, nama, jumlah, ket, tgl, lunas, tgl_lunas) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE nama=VALUES(nama), jumlah=VALUES(jumlah), ket=VALUES(ket), tgl=VALUES(tgl), lunas=VALUES(lunas), tgl_lunas=VALUES(tgl_lunas)')
      ->execute([$id, $nama, to_int(isset($h['jumlah']) ? $h['jumlah'] : 0, 0, 1000000000), cut(isset($h['ket']) ? $h['ket'] : '', 120), to_date(isset($h['tgl']) ? $h['tgl'] : ''), $lunas ? 1 : 0, $lunas ? to_date(isset($h['tglLunas']) ? $h['tglLunas'] : '') : null]);
}
function save_tabungan($p, $s) {
    $id = is_array($s) ? clean_id(isset($s['id']) ? $s['id'] : '') : null;
    if (!$id) throw new ApiError(400, 'Penabung tidak valid');
    $nama = trim(cut(isset($s['nama']) ? $s['nama'] : '', 60));
    if ($nama === '') throw new ApiError(400, 'Nama wajib diisi');
    $txs = []; $saldo = 0;
    foreach (array_slice(isset($s['tx']) && is_array($s['tx']) ? $s['tx'] : [], 0, 2000) as $t) {
        if (!is_array($t)) continue;
        $tipe = isset($t['tipe']) ? $t['tipe'] : '';
        if (!in_array($tipe, ['setor', 'tarik', 'iuran'], true)) continue;
        $tid = clean_id(isset($t['id']) ? $t['id'] : '');
        if (!$tid) $tid = substr(bin2hex(random_bytes(6)), 0, 12);
        $j = to_int(isset($t['jumlah']) ? $t['jumlah'] : 0, 0, 1000000000);
        $saldo += ($tipe === 'setor') ? $j : -$j;
        $txs[] = [$tid, to_date(isset($t['tgl']) ? $t['tgl'] : ''), $tipe, $j, cut(isset($t['ket']) ? $t['ket'] : '', 120),
                  clean_id(isset($t['sid']) ? $t['sid'] : ''), clean_id(isset($t['pid']) ? $t['pid'] : '')];
    }
    if ($saldo < 0) throw new ApiError(400, 'Saldo tabungan tidak boleh minus');
    $p->prepare('INSERT INTO penabung (id, nama) VALUES (?,?) ON DUPLICATE KEY UPDATE nama=VALUES(nama)')->execute([$id, $nama]);
    $p->prepare('DELETE FROM tabungan_tx WHERE penabung_id = ?')->execute([$id]);
    $ins = $p->prepare('INSERT INTO tabungan_tx (id, penabung_id, tgl, tipe, jumlah, ket, sesi_id, pemain_id) VALUES (?,?,?,?,?,?,?,?)');
    foreach ($txs as $t) $ins->execute([$t[0], $id, $t[1], $t[2], $t[3], $t[4], $t[5], $t[6]]);
}
function del_row($p, $table, $id) {
    $id = (string)$id;
    $p->prepare("DELETE FROM $table WHERE id = ?")->execute([$id]);
}
function run_op($p, $op, $in) {
    switch ($op) {
        case 'save_sesi':     save_sesi($p, isset($in['item']) ? $in['item'] : null); break;
        case 'del_sesi':      del_row($p, 'sesi', isset($in['id']) ? $in['id'] : ''); break;
        case 'save_hutang':   save_hutang($p, isset($in['item']) ? $in['item'] : null); break;
        case 'del_hutang':    del_row($p, 'hutang', isset($in['id']) ? $in['id'] : ''); break;
        case 'save_tabungan': save_tabungan($p, isset($in['item']) ? $in['item'] : null); break;
        case 'del_tabungan':  del_row($p, 'penabung', isset($in['id']) ? $in['id'] : ''); break;
        default: throw new ApiError(400, 'Aksi tidak dikenal');
    }
}

try {
    $p = pdo();
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $a = isset($_GET['action']) ? $_GET['action'] : '';
        if ($a === 'me') {
            ensure_schema($p);
            $n = (int)$p->query('SELECT COUNT(*) FROM users')->fetchColumn();
            out(200, ['user' => public_user(current_user($p)), 'needsSetup' => $n === 0]);
        }
        $u = current_user($p);
        if (!$u) throw new ApiError(401, 'Belum masuk');
        if ($a === 'sync') {
            $data = load_all($p);
            $hash = md5(json_encode($data) . $u['role']);
            if (isset($_GET['h']) && $_GET['h'] === $hash) out(200, ['same' => true, 'hash' => $hash, 'user' => public_user($u)]);
            out(200, ['same' => false, 'hash' => $hash, 'user' => public_user($u), 'data' => $data]);
        }
        if ($a === 'users') {
            if ($u['role'] !== 'admin') throw new ApiError(403, 'Hanya admin');
            $res = [];
            foreach ($p->query('SELECT username, role FROM users ORDER BY role, username') as $r) $res[] = ['username' => $r['username'], 'role' => $r['role']];
            out(200, $res);
        }
        throw new ApiError(400, 'Aksi tidak dikenal');
    }

    if ($method !== 'POST') throw new ApiError(405, 'Metode tidak didukung');
    if (stripos(isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '', 'application/json') === false) throw new ApiError(400, 'Permintaan tidak valid');
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) throw new ApiError(400, 'Data tidak valid');
    $a = isset($in['action']) ? $in['action'] : '';

    if ($a === 'setup') {
        ensure_schema($p);
        $n = norm_user(isset($in['username']) ? $in['username'] : ''); $pw = (string)(isset($in['password']) ? $in['password'] : '');
        check_account($n, $pw);
        $p->beginTransaction();
        $cnt = (int)$p->query('SELECT COUNT(*) FROM users FOR UPDATE')->fetchColumn();
        if ($cnt > 0) { $p->rollBack(); throw new ApiError(403, 'Akun admin sudah dibuat'); }
        $p->prepare("INSERT INTO users (username, password_hash, role, created_at) VALUES (?,?, 'admin', NOW())")->execute([$n, password_hash($pw, PASSWORD_DEFAULT)]);
        $uid = (int)$p->lastInsertId();
        $p->commit();
        session_regenerate_id(true); $_SESSION['uid'] = $uid;
        out(200, ['user' => ['username' => $n, 'role' => 'admin']]);
    }
    if ($a === 'login') {
        $n = norm_user(isset($in['username']) ? $in['username'] : ''); $pw = (string)(isset($in['password']) ? $in['password'] : '');
        $st = $p->prepare('SELECT id, username, password_hash, role FROM users WHERE username = ?');
        $st->execute([$n]);
        $x = $st->fetch();
        if (!$x || !password_verify($pw, $x['password_hash'])) { usleep(700000); throw new ApiError(401, 'Nama pengguna atau kata sandi salah'); }
        session_regenerate_id(true); $_SESSION['uid'] = (int)$x['id'];
        out(200, ['user' => ['username' => $x['username'], 'role' => $x['role']]]);
    }
    if ($a === 'logout') { $_SESSION = []; session_destroy(); out(200, ['ok' => true]); }

    $u = current_user($p);
    if (!$u) throw new ApiError(401, 'Belum masuk');
    if ($u['role'] !== 'admin') throw new ApiError(403, 'Hanya admin yang bisa melakukan ini');

    if ($a === 'adduser') {
        $n = norm_user(isset($in['username']) ? $in['username'] : ''); $pw = (string)(isset($in['password']) ? $in['password'] : '');
        $role = (isset($in['role']) && $in['role'] === 'admin') ? 'admin' : 'anggota';
        check_account($n, $pw);
        $st = $p->prepare('SELECT 1 FROM users WHERE username = ?'); $st->execute([$n]);
        if ($st->fetch()) throw new ApiError(409, 'Nama pengguna sudah dipakai');
        $p->prepare('INSERT INTO users (username, password_hash, role, created_at) VALUES (?,?,?,NOW())')->execute([$n, password_hash($pw, PASSWORD_DEFAULT), $role]);
        out(200, ['ok' => true]);
    }
    if ($a === 'deluser') {
        $n = norm_user(isset($in['username']) ? $in['username'] : '');
        if ($n === $u['username']) throw new ApiError(400, 'Tidak bisa menghapus akun sendiri');
        $p->prepare('DELETE FROM users WHERE username = ?')->execute([$n]);
        out(200, ['ok' => true]);
    }
    if ($a === 'setpw') {
        $n = norm_user(isset($in['username']) ? $in['username'] : ''); $pw = (string)(isset($in['password']) ? $in['password'] : '');
        if (strlen($pw) < 6) throw new ApiError(400, 'Kata sandi minimal 6 karakter');
        $st = $p->prepare('UPDATE users SET password_hash = ? WHERE username = ?');
        $st->execute([password_hash($pw, PASSWORD_DEFAULT), $n]);
        out(200, ['ok' => true]);
    }
    if ($a === 'batch') {
        $ops = isset($in['ops']) && is_array($in['ops']) ? array_slice($in['ops'], 0, 20) : [];
        $p->beginTransaction();
        try { foreach ($ops as $o) { if (is_array($o)) run_op($p, isset($o['op']) ? $o['op'] : '', $o); } $p->commit(); }
        catch (Exception $e) { $p->rollBack(); throw $e; }
        out(200, ['ok' => true]);
    }
    // Aksi tunggal: save_sesi, del_sesi, save_hutang, del_hutang, save_tabungan, del_tabungan
    $p->beginTransaction();
    try { run_op($p, $a, $in); $p->commit(); }
    catch (Exception $e) { $p->rollBack(); throw $e; }
    out(200, ['ok' => true]);

} catch (ApiError $e) {
    out($e->status, ['error' => $e->getMessage()]);
} catch (Exception $e) {
    out(500, ['error' => 'Kesalahan server. Pastikan konfigurasi database benar.']);
}
