<?php
/** Resolve only real, public profile images; never expose filesystem paths. */
function profile_image_path(?string $stored): ?string {
    $path=trim((string)$stored);
    if($path==='' || str_contains($path,"\0") || str_contains($path,'\\'))return null;
    if(preg_match('#^https?://#i',$path)) {
        $parts=parse_url($path);$base=parse_url(APP_URL);
        if(!$parts || strcasecmp($parts['host']??'', $base['host']??'')!==0 || ($parts['port']??null)!==($base['port']??null))return null;
        $path=$parts['path']??'';
    } else { $path=explode('?',explode('#',$path,2)[0],2)[0]; }
    $path=rawurldecode($path);$basePath=rtrim(parse_url(APP_URL,PHP_URL_PATH)??'','/');
    if($basePath!=='' && str_starts_with($path,$basePath.'/'))$path=substr($path,strlen($basePath)+1);
    $path=ltrim($path,'/');
    if(str_contains($path,'..') || str_contains($path,'\\') || !preg_match('#^assets/images/(?:profiles/[^/]+|[^/]+)\.(?:png|jpe?g|webp|svg)$#iD',$path))return null;
    $root=realpath(BASE_PATH.'/assets/images');$full=realpath(BASE_PATH.'/'.$path);
    if(!$root || !$full || !str_starts_with($full,$root.DIRECTORY_SEPARATOR) || !is_file($full))return null;
    if(strtolower(pathinfo($full,PATHINFO_EXTENSION))==='svg')return $path==='assets/images/default-avatar.svg'?$path:null;
    if(@getimagesize($full)===false)return null;
    return $path;
}
function avatar_url(?string $stored=null): string {
    static $urls=[];
    $path=profile_image_path($stored);
    if(!$path)return asset_url('images/default-avatar.svg');
    if(isset($urls[$path]))return $urls[$path];
    $full=BASE_PATH.'/'.$path;
    return $urls[$path]=app_url($path).'?v='.substr(hash_file('sha256',$full),0,12);
}
