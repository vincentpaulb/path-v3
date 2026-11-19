<?php
namespace App\Enums;
enum FundingStatus:string { case PROCESSING='Ongoing Processing'; case RELEASED='Cheque Released'; public static function values():array{return array_column(self::cases(),'value');} }
