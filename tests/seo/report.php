<?php
// Offline test of the report scoring/formatting (no WordPress): stubs the few WP helpers used.
define('ABSPATH', 1); define('DAY_IN_SECONDS', 86400);
function esc_html($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');} function esc_attr($s){return esc_html($s);} function esc_url($s){return (string)$s;}
function home_url($p=''){return 'https://oxygen11.com'.$p;} function wp_parse_url($u,$c=-1){return parse_url($u,$c);}
function get_option($k,$d=false){return $d;} function wp_date($f,$t=null){return gmdate($f,$t??time());}
require __DIR__.'/../../oxygen-ac-automation/includes/class-oxygen-seo-engine.php';
require __DIR__.'/../../oxygen-ac-automation/includes/class-oxygen-daily-report.php';
$f=0; $plan=Oxygen_SEO_Engine::plan(); $tot=0; $n=0; $min=100; $max=0; $fails=[];
foreach(array_slice($plan,0,120) as $it){
  $b=Oxygen_SEO_Engine::build($it,0,['app'=>'https://oxygen11.com/app/','wa'=>'966500000000','img'=>'']);
  [$s,$fl]=Oxygen_Daily_Report::score_html($it['title'],$b['html'],$it['kw'],$it['key'],$b['desc'],'https://oxygen11.com/');
  if($s<0||$s>100){$f++;echo "BAD score $s\n";}
  $tot+=$s;$n++;$min=min($min,$s);$max=max($max,$s); foreach($fl as $k) $fails[$k]=($fails[$k]??0)+1;
}
arsort($fails);
echo "articles=$n avg=".round($tot/$n,1)." min=$min max=$max\n"; echo "top fails: ".json_encode(array_slice($fails,0,6),JSON_UNESCAPED_UNICODE)."\n";
$w=array_sum(array_column(Oxygen_Daily_Report::$checks,0)); echo "weights sum=$w\n"; if($w!==100)$f++;
// a hand-made perfect article must score 100; an empty one near 0
$kw='صيانة مكيفات مكة';
$html='<p>'.$kw.' من أكسجين. </p><h2>أسباب '.$kw.'</h2><img src="a.jpg" alt="'.$kw.'"><ul><li>أ</li></ul><a href="/app/">طلب</a><a href="https://example.org/x">مصدر</a>'.str_repeat('<p>'.str_repeat('كلمة ',40).'</p>',20).'<p>'.$kw.'</p>';
[$s,$fl]=Oxygen_Daily_Report::score_html($kw.' بأفضل سعر وجودة عالية',$html,$kw,'صيانة-مكيفات-مكة',str_repeat('x',120)." $kw",'https://oxygen11.com/');
echo "handmade score=$s fails=".json_encode($fl,JSON_UNESCAPED_UNICODE)."\n";
[$e]=Oxygen_Daily_Report::score_html('x','','kw','x','','https://oxygen11.com/'); echo "empty score=$e\n";
// formatting
$rows=[]; for($i=0;$i<180;$i++){ $rows[]=['id'=>$i,'a'=>['new_seo','refresh_rand','refresh'][$i%3],'ts'=>1761900000+$i*60,'t'=>"مقال $i [x]",'l'=>"https://oxygen11.com/p$i/",'kw'=>'كلمة|'.$i,'s'=>($i*7)%101,'f'=>['kw_url','len']]; }
$rep=Oxygen_Daily_Report::summarize('2026-10-30',$rows);
$rep['brand']='أكسجين'; $rep['site']='oxygen11.com';
$md=Oxygen_Daily_Report::markdown($rep); $csv=Oxygen_Daily_Report::csv($rep); $html=Oxygen_Daily_Report::html($rep); $tg=Oxygen_Daily_Report::telegram_text($rep);
echo "md=".strlen($md)." csv=".strlen($csv)." html=".strlen($html)." tg=".mb_strlen($tg)."\n";
if(strlen($md)>65000||mb_strlen($tg)>4000)$f++;
if(substr_count($md,"\n| ")<180)$f++; if(substr_count($csv,"\n")<181)$f++;
echo "ALL OK\n"; exit($f?1:0);
