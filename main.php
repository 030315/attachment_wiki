<?php
/**
 * Backlogのwikiに添付ファイルを送信する
 */
require 'Config.php';

Config::set_config_directory(__DIR__ . '/config');

$directory_path = Config::get('settings.DIRECTORY_PATH');
$generation = Config::get('settings.GENERATION');

print_r("===START===\n");
print_r($directory_path.'*'."\n");

backupMySQL();

// ファイルの更新日の取得
$filelist = [];
foreach (glob($directory_path.'*') as $filepath) {
  if (is_file($filepath)) {
    $key = filemtime($filepath);
    $filelist[$key] = $filepath;
  }
}
// 更新日の降順ソート
rsort($filelist);

removeFileOverGeneration($filelist);

$filelist = array_slice($filelist, 0, $generation);
removeAllFile();

foreach ($filelist as $filepath) {
  $filename = end(explode('/', $filepath));
  uploadFile($filepath, $filename);
}
print_r("=== END ===\n");

/**
 * 世代を超えたファイルを削除
 */
function removeFileOverGeneration($filelist)
{
  $generation = Config::get('settings.GENERATION');
  // 世代以上のファイル数ならばデータを削除
  if (count($filelist) > $generation) {
    $over_filelist = array_slice($filelist, $generation, count($filelist));
    foreach ($over_filelist as $over_filepath) {
      unlink($over_filepath);
    }
  }
}

/**
 * MySQLのバックアップ（要：mysqldump）
 */
function backupMySQL()
{
  $mysql = Config::get('settings.MYSQL');
  $directory_path = Config::get('settings.DIRECTORY_PATH');
  $filename = $mysql['DBNAME'].(new DateTimeImmutable())->format('Y-m-d_His').'.sql';
  $dump_cmd = 'mysqldump -u '.$mysql['USER'].' -p'.$mysql['PASSWORD'].' -h '.$mysql['HOST'].' -P '. $mysql['PORT'].' '.$mysql['DBNAME'].' --column-statistics=0';

  shell_exec($dump_cmd.' > '.$directory_path.$filename);
}

/**
 * ファイルのアップロード
 */
function uploadFile($filepath, $filename)
{
  // 設定ファイルの読込
  $backlog = Config::get('settings.BACKLOG');
  $wiki_id = $backlog['WIKI_ID'];
  $api_key = $backlog['API_KEY'];
  $url = $backlog['URL'];
  // 添付ファイルのアップロード
  $wiki_url = 'api/v2/wikis/'.$wiki_id.'/attachments';
  $space_url = 'api/v2/space/attachment';
  $query_api = '?apiKey='.$api_key;

  $file = new CURLFile($filepath, 'text/comma-separated-values', $filename);
  $postfields = ['file' => $file];
  $header = [
    'Content-Disposition: form-data; name="file"; filename=' . $filename .
    'Content-Type:application/octet-stream'
  ];
  $attachment_url = $url.$space_url.$query_api;
  $result = curlPOST($attachment_url, $header, $postfields);

  // AttachmentIDを取得してwikiにアップロード
  print_r($result."\n");
  $result = json_decode($result, true);
  $id = $result['id'];

  $attachment_wiki_url = $url.$wiki_url.$query_api.'&attachmentId[]='.$id;
  $result = curlPOST($attachment_wiki_url, $header, $postfields);

  print_r($result."\n");
}

/**
 * ファイルの一覧を取得して削除する
 */
function removeAllFile()
{
  // 設定ファイルの読込
  $backlog = Config::get('settings.BACKLOG');
  $url = $backlog['URL'];
  $wiki_id = $backlog['WIKI_ID'];
  $api_key = $backlog['API_KEY'];

  $wiki_url = 'api/v2/wikis/'.$wiki_id.'/attachments';
  $query_api = '?apiKey='.$api_key;
  $result = curlGET($url.$wiki_url.$query_api);

  $result = json_decode($result, true);

  foreach ($result as $file) {
    curlDELETE($url.$wiki_url.'/'.$file['id'].$query_api);
  }

  return $result;
}

/**
 * データのGET
 */
function curlGET($url)
{
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  $result = curl_exec($ch);
  curl_close($ch);
  return $result;
}

/**
 * データのPOST
 */
function curlPOST($url, $header, $postfields)
{
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
  curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $postfields);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  $result = curl_exec($ch);
  curl_close($ch);
  return $result;
}

/**
 * データのDELETE
 */
function curlDELETE($url)
{
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  $result = curl_exec($ch);
  curl_close($ch);
  return $result;
}
