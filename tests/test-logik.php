<?php
define('ABSPATH','/');
$GLOBALS['opts']=[];$GLOBALS['filters']=[];
function add_filter($h,$c,$p=10,$a=1){$GLOBALS['filters'][$h][]=$c;}
function add_action(...$a){}
function is_admin(){return false;}
function get_option($k,$d=false){return $GLOBALS['opts'][$k]??$d;}
function update_option($k,$v){$GLOBALS['opts'][$k]=$v;}
function wp_parse_args($a,$d){return array_merge($d,$a);}
function sanitize_text_field($s){return trim($s);}
function sanitize_key($s){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',$s));}
function wp_unslash($s){return $s;}
function apply_filters($h,$v){return $v;}
function remove_accents($s){return strtr($s,['å'=>'a','ä'=>'a','ö'=>'o','Å'=>'A','Ä'=>'A','Ö'=>'O']);}
class WPCF7_Pipes{public $r;function __construct($r){$this->r=$r;}}
class WPCF7_ContactForm{static $attr='';static function get_current(){return new self;} function shortcode_attr($k){return self::$attr;}}
class Tag{public $type,$basetype,$name,$options,$raw_values,$values,$labels,$pipes;}
require __DIR__.'/../bokningsformular.php';
function mk($type,$name,$vals,$opts=['include_blank']){$t=new Tag;$t->type=$type;$t->basetype=rtrim($type,'*');$t->name=$name;$t->options=$opts;$t->raw_values=$vals;$t->values=$vals;$t->labels=$vals;return $t;}
$B=["Vanlig cykel","Elcykel","Sportig cykel","Tar med egen"];$H=["Hyr hjälm","Tar med egen","Hjälm ingår"];
$GLOBALS['opts']['cvb_settings']=[
 'EB-1'=>['hjalm'=>'nej','vanlig'=>1,'el'=>1,'sportig'=>1,'egen'=>1,'stold'=>0,'assistans'=>0,'klar'=>1],
 'EL-1'=>['hjalm'=>'ingar','vanlig'=>0,'el'=>1,'sportig'=>0,'egen'=>0,'stold'=>0,'assistans'=>0,'klar'=>1],
 'NL-1'=>['hjalm'=>'hyra','vanlig'=>1,'el'=>1,'sportig'=>0,'egen'=>1,'stold'=>1,'assistans'=>1,'klar'=>1],
];
foreach(['EB-1','EL-1','NL-1','OKAND'] as $c){
 WPCF7_ContactForm::$attr=$c;
 $b=CV_Bokningsval::filter_form_tag(mk('select*','cykel-3',$B));
 $h=CV_Bokningsval::filter_form_tag(mk('select*','hjalm-1',$H));
 $s=CV_Bokningsval::filter_form_tag(mk('select*','cykelforsakring',["Ja","Nej"]));
 echo "$c: cykel=".implode('/',$b->values)." | hjälm=".implode('/',$h->values)." blank=".(in_array('include_blank',$h->options)?'y':'n')." | stöld type=".$s->type."\n";
}
// POST path
$_POST=['_wpcf7'=>'1','tripcode'=>'EL-1']; WPCF7_ContactForm::$attr='';
echo "POST: ".CV_Bokningsval::current_tripcode()."\n"; $_POST=[];
WPCF7_ContactForm::$attr='EB-1';
$html="<p>x</p><div class=\"cvb-stold\"><label> Stöld <span class=\"wpcf7-form-control-wrap\"><select></select></span></label><br />\n</div><div class=\"cvb-assistans\"><label>A</label></div><p>y</p>";
echo CV_Bokningsval::filter_form_html($html),"\n";
WPCF7_ContactForm::$attr='NL-1'; echo CV_Bokningsval::filter_form_html($html)===$html?"NL keeps\n":"NL BAD\n";
var_dump(CV_Bokningsval::extract_codes('[contact-form-7 id="2b3b45c" title="x (ej hjälm)" tripcode="DE-MORTK-04A"]'));
var_dump(CV_Bokningsval::extract_codes('[fusion_code]'.base64_encode('[contact-form-7 id="1" tripcode="NL-HOL-01"]').'[/fusion_code]'));
var_dump(CV_Bokningsval::extract_codes('tripcode=&quot;IT-X1&quot;'));
// unrelated tag untouched
$u=mk('text*','namn-2',[]); var_dump(CV_Bokningsval::filter_form_tag($u)===$u);
// --- vandring
function get_post_type($id){return 'page';}
function get_permalink($id){return $id==50?'https://cyklavandra.se/vandra/vandra-pa-egen-hand/madeira/':'https://cyklavandra.se/cykla/x/';}
function get_the_ID(){return $GLOBALS['cur']??0;}
function absint($v){return abs((int)$v);}
$GLOBALS['cur']=50; WPCF7_ContactForm::$attr='PT-MAD';
$l=CV_Bokningsval::filter_form_tag(mk('number*','langd-1',[],[]));
$b=CV_Bokningsval::filter_form_tag(mk('select*','cykel-1',$B));
echo "hike: langd={$l->type} cykel={$b->type} n=".count($b->values)."\n";
$html='<div class="resenar-rad"><label>Namn</label></div><div class="resenar-rad cvb-cykel"><label>Längd</label><label>Cykel</label></div><p>z</p>';
echo CV_Bokningsval::filter_form_html($html),"\n";
$_POST=['_wpcf7'=>'1','tripcode'=>'PT-MAD','_wpcf7_container_post'=>'50']; $GLOBALS['cur']=0;
$l=CV_Bokningsval::filter_form_tag(mk('number*','langd-2',[],[])); echo "submit hike langd={$l->type}\n"; $_POST=[];
$GLOBALS['cur']=60; WPCF7_ContactForm::$attr='NL-1';
$l=CV_Bokningsval::filter_form_tag(mk('number*','langd-1',[],[])); echo "bike langd={$l->type}\n";
echo CV_Bokningsval::filter_form_html($html)===$html ? "bike html kept\n":"BAD\n";
// stored typ overrides guess
$GLOBALS['opts']['cvb_settings']['PT-MAD']=['typ'=>'cykel','klar'=>1]; $GLOBALS['cur']=50; WPCF7_ContactForm::$attr='PT-MAD';
$l=CV_Bokningsval::filter_form_tag(mk('number*','langd-1',[],[])); echo "stored cykel langd={$l->type}\n";
