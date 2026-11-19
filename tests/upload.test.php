<?php
/** MP3 uploads in parts: only what was announced, imported only when complete. */
declare(strict_types=1);

$error = static function (callable $fn): string {
    try {
        $fn();
        return 'no error';
    } catch (InputError $e) {
        return $e->getMessage();
    }
};
$files = static fn(string $id): array => [is_file(upload_dir() . "/$id.part"), is_file(upload_dir() . "/$id.size")];
$part  = tempnam(sys_get_temp_dir(), 'r1part');

// four of ten bytes, then finish: refused, and nothing stays behind
$id = upload_start(10);
check('start writes part and size', $files($id), [true, true]);
check('announced size', upload_expected(upload_dir() . "/$id.part"), 10);
file_put_contents($part, 'abcd');
check('chunk 4 of 10', upload_chunk($id, 0, $part), 4);
check('finish incomplete', $error(fn() => upload_finish($id, 'x.mp3')), 'Upload unvollständig, bitte erneut versuchen.');
check('incomplete cleaned up', $files($id), [false, false]);

// more than announced, a wrong offset
$id = upload_start(5);
file_put_contents($part, 'abcdef');
check('chunk beyond size', $error(fn() => upload_chunk($id, 0, $part)), 'Teil passt nicht zur Größe der Datei, bitte erneut versuchen.');
file_put_contents($part, 'abcde');
check('chunk of the size', upload_chunk($id, 0, $part), 5);
check('wrong offset', $error(fn() => upload_chunk($id, 3, $part)), 'Upload unterbrochen, bitte erneut versuchen.');
// complete but no MP3: the size check passes, the import refuses, the parts go
check('complete, no mp3', $error(fn() => upload_finish($id, 'x.mp3')) !== 'Upload unvollständig, bitte erneut versuchen.', true);
check('refused cleaned up', $files($id), [false, false]);

// an upload from before 1.27.1 without a size file: no part fits, finishing refuses
$id = upload_start(5);
unlink(upload_dir() . "/$id.size");
check('old upload chunk', $error(fn() => upload_chunk($id, 0, $part)), 'Teil passt nicht zur Größe der Datei, bitte erneut versuchen.');
check('old upload finish', $error(fn() => upload_finish($id, 'x.mp3')), 'Upload unvollständig, bitte erneut versuchen.');
check('unknown upload', $error(fn() => upload_chunk('../../etc', 0, $part)), 'Upload nicht gefunden, bitte erneut versuchen.');

// the same part twice in a row: the second one is refused
$id = upload_start(8);
file_put_contents($part, 'abcd');
check('part once', upload_chunk($id, 0, $part), 4);
check('same part again', $error(fn() => upload_chunk($id, 0, $part)), 'Upload unterbrochen, bitte erneut versuchen.');
check('size after the double', filesize(upload_dir() . "/$id.part"), 4);

// the same part twice at the same time, from two processes: exactly one gets in (#72)
$id = upload_start(6);
file_put_contents($part, 'abcdef');
$code = 'require ' . var_export(dirname(__DIR__) . '/app/bootstrap.php', true) . ';'
    . 'try { echo upload_chunk($argv[1], 0, $argv[2]); } catch (InputError $e) { echo "refused"; }';
$procs = [];
$out   = [];
for ($i = 0; $i < 2; $i++) {   // R1_CONFIG from the runner goes along to the children
    $procs[$i] = proc_open([PHP_BINARY, '-r', $code, '--', $id, $part], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
}
for ($i = 0; $i < 2; $i++) {
    $out[$i] = trim((string) stream_get_contents($pipes[$i][1])) . trim((string) stream_get_contents($pipes[$i][2]));
    proc_close($procs[$i]);
}
sort($out);
check('two at once: one in, one refused', $out, ['6', 'refused']);
check('two at once: size', filesize(upload_dir() . "/$id.part"), 6);
check('two at once: content', file_get_contents(upload_dir() . "/$id.part"), 'abcdef');
@unlink($part);

// the private folder is the one of the config (#73)
check('private folder', private_dir(), dirname((string) config_path()));
