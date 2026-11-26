# 4 clients at the same time over a mix of panel screens; arms alternate
import subprocess,threading,time,random,json,os,statistics,sys
sys.path.insert(0,os.path.dirname(os.path.abspath(__file__))); os.chdir(os.environ['LAB_DIR'])
from senaryo import S; C=open('cookie.txt').read().strip(); U=[(k,u,l) for k,g,n,r,u,l,*_ in S if k not in('P7','P15','U2','W1') and not u.startswith('@')]
out={}
for rnd in range(3):
    for arm in (['stock','smt'] if rnd%2==0 else ['smt','stock']):
        lat=[]; end=time.time()+int(sys.argv[1]); err=[0]
        def run(seed):
            r=random.Random(seed); 
            while time.time()<end:
                k,u,l=r.choice(U); t=time.time(); c=['curl','-s','-o','/dev/null','-w','%{http_code}','--max-time','120','-b',C,'-H','X-Lab-Engine: '+arm]+(['-H','X-Lab-Legacy: 1','-H','X-Lab-Log: y'] if l else [])
                code=subprocess.run(c+['http://127.0.0.1:8099'+u],capture_output=True,text=True).stdout
                if code!='200': err[0]+=1
                lat.append(time.time()-t)
        th=[threading.Thread(target=run,args=(rnd*10+i,)) for i in range(4)]; [t.start() for t in th]; [t.join() for t in th]
        lat.sort(); out.setdefault(arm,[]).append(dict(n=len(lat),med=statistics.median(lat)*1000,p95=lat[int(len(lat)*.95)]*1000,err=err[0])); print(arm,out[arm][-1],flush=True)
json.dump(out,open(sys.argv[2],'w'),indent=1)
