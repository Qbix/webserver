<?php
/**
 * HTTP pipelining regression tests for bin/uwebserver.c
 *
 * A client may send several requests in a single write. The original do_read()
 * handled the first and returned; handle_request() sets buf_len = 0, so the
 * rest were discarded unread. Because the socket is EPOLLET those bytes never
 * produced another readiness edge, so epoll never reported the fd again and
 * the client blocked forever. Symptom: 1 of 3 responses.
 *
 * Build and run:
 *   gcc -O2 -o uweb bin/uwebserver.c -lssl -lcrypto -lpthread
 *   ./uweb --port=21010 &
 *   php tests/pipelining.php 21010
 */
$port=(int)$argv[1]; $pass=0; $fail=0;
function t($name,$got,$want){ global $pass,$fail;
  if($got===$want){$pass++; printf("  ok   %-34s %s\n",$name,$got);}
  else{$fail++; printf("  FAIL %-34s %s (want %s)\n",$name,$got,$want);} }
function pipe_n($port,$n,$extra=""){
  $fp=@fsockopen("127.0.0.1",$port,$e,$s,3); if(!$fp) return -1;
  $req=""; for($i=0;$i<$n;$i++) $req.="GET / HTTP/1.1\r\nHost: l\r\nConnection: keep-alive\r\n$extra\r\n";
  fwrite($fp,$req); stream_set_timeout($fp,3); $r="";
  while(!feof($fp)){$c=@fread($fp,65536); if($c===''||$c===false)break; $r.=$c;}
  fclose($fp); return substr_count($r,"HTTP/1.1");
}
t("3 pipelined",  pipe_n($port,3),  3);
t("10 pipelined", pipe_n($port,10), 10);
t("50 pipelined", pipe_n($port,50), 50);
t("1 (degenerate)",pipe_n($port,1), 1);

// pipelined where a LATER request says Connection: close
$fp=@fsockopen("127.0.0.1",$port,$e,$s,3);
$req ="GET / HTTP/1.1\r\nHost: l\r\nConnection: keep-alive\r\n\r\n";
$req.="GET / HTTP/1.1\r\nHost: l\r\nConnection: close\r\n\r\n";
fwrite($fp,$req); stream_set_timeout($fp,3); $r="";
while(!feof($fp)){$c=@fread($fp,65536); if($c===''||$c===false)break; $r.=$c;}
fclose($fp);
t("close in 2nd of 2", substr_count($r,"HTTP/1.1"), 2);

// request split across two writes (partial header)
$fp=@fsockopen("127.0.0.1",$port,$e,$s,3);
fwrite($fp,"GET / HTTP/1.1\r\nHost: l\r\n"); usleep(50000);
fwrite($fp,"Connection: close\r\n\r\n"); stream_set_timeout($fp,3); $r="";
while(!feof($fp)){$c=@fread($fp,65536); if($c===''||$c===false)break; $r.=$c;}
fclose($fp);
t("split across writes", substr_count($r,"HTTP/1.1"), 1);

// pipelined + trailing PARTIAL request (must not be lost or crash)
$fp=@fsockopen("127.0.0.1",$port,$e,$s,3);
fwrite($fp,"GET / HTTP/1.1\r\nHost: l\r\nConnection: keep-alive\r\n\r\nGET / HTTP/1.1\r\nHost: par");
usleep(50000);
fwrite($fp,"tial\r\nConnection: close\r\n\r\n"); stream_set_timeout($fp,3); $r="";
while(!feof($fp)){$c=@fread($fp,65536); if($c===''||$c===false)break; $r.=$c;}
fclose($fp);
t("pipelined + partial tail", substr_count($r,"HTTP/1.1"), 2);

echo "\n$pass passed, $fail failed\n";
exit($fail?1:0);
