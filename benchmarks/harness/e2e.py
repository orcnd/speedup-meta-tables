from cap import *
sys.path.insert(0,os.path.dirname(os.path.abspath(__file__))); from senaryo import S
import statistics,random,hashlib,os
ENG=tuple(os.environ.get('ENG','stock,h,o').split(','))
def nonce(a): get('/wp-admin/index.php?labnonce='+a,log='n'); return open('logs/body.html').read().strip()
nc=nonce('search-customers'); T=re.compile(r'wp_(postmeta|usermeta|wc_orders_meta)|speedup_meta_|FOUND_ROWS|VALUES ROW'); out={}
only=sys.argv[3:] ; N=int(sys.argv[2])
def sig(k,u):
    b=open('logs/body.html',errors='replace').read()
    if 'labjob' in u or 'labrest' in u: return b[:60] if 'labrest' in u else b[:200]
    ids=re.findall(r'id="post-(\d+)"|id="order-(\d+)"|id="user-(\d+)"|"(\d+)":"',b); n=re.findall(r'displaying-num">([^<]*)<',b)
    return hashlib.md5((str(ids if k!='P7' else len(ids))+str(n[:1])).encode()).hexdigest()[:10]+' '+str(n[:1])+str(len(ids))
for k,g,n,r,u,l,*_ in S:
    if only and k not in only: continue
    u=u.replace('@cust','/wp-admin/admin-ajax.php?action=woocommerce_json_search_customers&security=%s&term=amar'%nc)
    R={e:[] for e in ENG}; sg={}; est=0
    for i in range(N+1):
        es=list(ENG); random.shuffle(es)
        for e in es:
            if e=='stock' and i>0 and (est>15000 and i>1 or est>4000 and i>3): continue
            v=u.replace('@n',str(100+i*3+es.index(e)))
            d=get(v,l,engine=e,log=k); 
            if i==0 and e=='stock': est=d['wall']
            if i==0 and not (e=='stock' and est>15000): sg[e]=sig(k,u); continue
            if e=='stock' and i==0: sg[e]=sig(k,u)
            R[e].append((d['wall'],sum(x[0] for x in d['q']),sum(x[0] for x in d['q'] if T.search(x[1])),d['engine'],len(d.get('served',[])),d['code']))
    md=lambda a,j: statistics.median(x[j] for x in a) if a else 0
    out[k]={e:dict(wall=md(a,0),sql=md(a,1),meta=md(a,2),eng=md(a,3),served=md(a,4),n=len(a),code=a[-1][5] if a else 0) for e,a in R.items()}
    out[k]['same']={e:sg.get(e)==sg.get('stock') for e in ENG if e!='stock'}; out[k]['sig']=sg
    print(k,n,'| '+' | '.join(f"{e} wall {x['wall']:.0f} meta+eng {x['meta']+x['eng']:.1f} served {x['served']:.0f}" for e,x in out[k].items() if e in R),out[k]['same'], '' if all(out[k]['same'].values()) else sg,flush=True)
    json.dump(out,open(sys.argv[1],'w'),indent=1)
