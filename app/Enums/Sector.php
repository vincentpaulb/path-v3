<?php
namespace App\Enums;
enum Sector:string { case SETUP='SETUP'; case GIA='GIA'; case CEST='CEST'; case SMART='SMART'; public static function values():array{return array_column(self::cases(),'value');} }
