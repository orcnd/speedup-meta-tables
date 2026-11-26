# Loads every scenario once with the plugin observing (X-Lab-Learn: 1): the plugin records the meta queries itself.
from cap import *
import sys; sys.path.insert(0,os.path.dirname(os.path.abspath(__file__))); from senaryo import S
nc=open('logs/body.html').read() if False else None
def nonce(a): get('/wp-admin/index.php?labnonce='+a,log='n'); return open('logs/body.html').read().strip()
nc=nonce('search-customers')
for rep in range(int(sys.argv[1]) if len(sys.argv)>1 else 1):
    for k,g,n,r,u,l,*_ in S:
        u=u.replace('@cust','/wp-admin/admin-ajax.php?action=woocommerce_json_search_customers&security=%s&term=amar'%nc).replace('@n','100')
        c=['curl','-s','-o','/dev/null','-w','%{http_code}','--max-time','300','-b',C,'-H','X-Lab-Engine: smt','-H','X-Lab-Learn: 1','-H','X-Lab-Log: learn']+(['-H','X-Lab-Legacy: 1'] if l else [])
        print(k,subprocess.run(c+['http://127.0.0.1:8099'+u],capture_output=True,text=True).stdout,flush=True)
