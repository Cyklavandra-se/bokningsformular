<?php
require __DIR__.'/test-logik.php';
class WP_Error{public $m;function __construct($c,$m){$this->m=$m;}function get_error_message(){return $this->m;}}
function is_wp_error($e){return $e instanceof WP_Error;}
function wp_list_pluck($a,$k){return array_column($a,$k);}
$posts=[ (object)['ID'=>10,'post_title'=>'Upptäck Hollands pärlor med cykel','post_type'=>'page','post_status'=>'publish','post_content'=>'[contact-form-7 id="a" tripcode="NL-HOL"]'],
 (object)['ID'=>11,'post_title'=>'Donau genom Bayern','post_type'=>'page','post_status'=>'publish','post_content'=>'[contact-form-7 id="b" tripcode="DE-DON"]']];
$wpdb=new class{public $posts='wp_posts';function get_results($q){global $posts;return $posts;}};
function url_to_postid($u){return str_contains($u,'/holland/')?10:0;}
function get_post_field($f,$id){global $posts;foreach($posts as $p) if($p->ID==$id) return $p->post_content;}
$GLOBALS['opts']=[];
$csv="\xEF\xBB\xBFResa,Hjälm,Vanlig cykel,Elcykel,Sportig cykel,Egen cykel,Stöldförsäkring,Assistansförsäkring,Kommentar,Land/region,Webbsida\n".
"Upptäck Hollands pärlor med cykel,Att hyra,TRUE,TRUE,FALSE,TRUE,TRUE,TRUE,Kolla,Holland,https://cyklavandra.se/cykla/cykla-pa-egen-hand/holland/\n".
"Donau genom Bayern,,TRUE,TRUE,TRUE,TRUE,FALSE,FALSE,,Tyskland,https://cyklavandra.se/cykla/cykla-pa-egen-hand/donau-bayern/\n".
"Okänd resa,Ingår,TRUE,TRUE,FALSE,TRUE,FALSE,FALSE,,X,https://x/\n";
file_put_contents('/tmp/c.csv',$csv);
print_r(CV_Bokningsval::import_csv_file('/tmp/c.csv'));
print_r($GLOBALS['opts']['cvb_settings']);
