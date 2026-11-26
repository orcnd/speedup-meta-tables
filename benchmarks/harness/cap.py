import subprocess,json,sys,re
import os
os.chdir(os.environ['LAB_DIR'])
C=open('cookie.txt').read().strip()
def get(url,legacy=0,data=None,log='x',engine='stock'):
    try: os.remove('logs/req.json')
    except FileNotFoundError: pass
    c=['curl','-s','-o','logs/body.html','-w','%{http_code}','--max-time','300','-b',C,'-H','X-Lab-Log: '+log,'-H','X-Lab-Engine: '+engine]
    if legacy: c+=['-H','X-Lab-Legacy: 1']
    if data is not None: c+=['--data',data]
    code=subprocess.run(c+['http://127.0.0.1:8099'+url],capture_output=True,text=True).stdout
    try: d=json.load(open('logs/req.json'))
    except FileNotFoundError: d=dict(q=[],wall=0,engine=0)
    d['code']=code; return d
META=re.compile(r'postmeta|usermeta|orders_meta')
if __name__=='__main__':
    url=sys.argv[1]; leg=int(sys.argv[2]) if len(sys.argv)>2 else 0
    get(url,leg); d=get(url,leg)
    print(d['code'],round(d['wall']),'ms',len(d['q']),'queries sql',round(sum(x[0] for x in d['q'])),'meta sql',round(sum(x[0] for x in d['q'] if META.search(x[1]))))
    for t,s,c in sorted(d['q'],key=lambda x:-x[0])[:int(sys.argv[3]) if len(sys.argv)>3 else 6]: print(' ',t,re.sub(r'\s+',' ',s)[:420],'|',c[-110:])
