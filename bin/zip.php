<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||count($argv)!==3)exit(1);
$root=realpath($argv[1]);if(!$root||is_file($argv[2]))exit("Invalid root or archive already exists\n");
$zip=new ZipArchive;if($zip->open($argv[2],ZipArchive::CREATE|ZipArchive::EXCL)!==true)exit(1);
$files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));foreach($files as$file){if(!$file->isFile())continue;$relative=str_replace('\\','/',substr($file->getPathname(),strlen($root)+1));$zip->addFile($file->getPathname(),$relative);}
$zip->close();echo basename($argv[2])." created\n";
