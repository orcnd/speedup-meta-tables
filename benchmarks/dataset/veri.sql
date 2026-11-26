set session cte_max_recursion_depth=200000; set sql_mode='';
drop table if exists lab_n; create table lab_n (n int primary key); insert into lab_n with recursive r as (select 1 n union all select n+1 from r where n<50000) select n from r;
drop table if exists lab_p; create table lab_p (ID bigint unsigned primary key, rn int, t varchar(20), key(rn));
insert into lab_p select ID,row_number() over(order by ID),post_type from wp_posts where post_type in('product','product_variation');
-- custom product fields as stores add them: supplier, cost, ERP id, EAN, shelf, supplier lead time
insert into wp_postmeta(post_id,meta_key,meta_value) select ID,'_supplier',concat('SUP-',lpad(1+floor(pow((rn*7919%10007)/10007,2)*60),3,'0')) from lab_p where t='product' and rn%20<17;
insert into wp_postmeta(post_id,meta_key,meta_value) select ID,'_purchase_cost',format(2+(rn*104729%19000)/100,2) from lab_p where rn%10<9;
insert into wp_postmeta(post_id,meta_key,meta_value) select ID,'_erp_id',concat('ERP-',lpad(rn,7,'0')) from lab_p;
insert into wp_postmeta(post_id,meta_key,meta_value) select ID,'_ean',concat('40',lpad(rn*7919,11,'0')) from lab_p where rn%25<21;
insert into wp_postmeta(post_id,meta_key,meta_value) select ID,'_shelf',concat(char(65+rn%8),'-',1+rn%40,'-',1+rn%6) from lab_p where t='product' and rn%3>0;
insert into wp_postmeta(post_id,meta_key,meta_value) select ID,'_discontinued','yes' from lab_p where t='product' and rn%37=0;
-- sales: 12% of products, 8% with an end date
update wp_postmeta m join lab_p p on p.ID=m.post_id and p.t='product' and p.rn%25<3 join wp_postmeta r on r.post_id=p.ID and r.meta_key='_regular_price' set m.meta_value=round(r.meta_value*0.8,2) where m.meta_key='_sale_price' and r.meta_value<>'';
update wp_postmeta m join lab_p p on p.ID=m.post_id and p.t='product' and p.rn%25<2 set m.meta_value=unix_timestamp('2026-11-01'+interval (p.rn%90) day) where m.meta_key='_sale_price_dates_to';
update wp_wc_product_meta_lookup l join lab_p p on p.ID=l.product_id and p.t='product' and p.rn%25<3 set l.onsale=1;
delete m from wp_postmeta m join lab_p p on p.ID=m.post_id and p.t='product' and p.rn%17=0 where m.meta_key='_thumbnail_id';
update wp_postmeta m join lab_p p on p.ID=m.post_id and p.t='product' and p.rn%6=0 set m.meta_value=5+p.rn%30 where m.meta_key='_low_stock_amount';
-- coupons
insert into wp_posts(post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,comment_status,ping_status,post_name,to_ping,pinged,post_modified,post_modified_gmt,post_content_filtered,guid,post_type) select 1,'2026-11-20'-interval n*5 hour,'2026-11-20'-interval n*5 hour,'',concat(elt(1+n%5,'welcome','spring','vip','back','sms'),n),'','publish','closed','closed',concat('c',n),'','','2026-11-20','2026-11-20','','','shop_coupon' from lab_n where n<=4000;
drop table if exists lab_c; create table lab_c select ID,row_number() over(order by ID) n from wp_posts where post_type='shop_coupon';
insert into wp_postmeta(post_id,meta_key,meta_value) select ID,'discount_type',elt(1+n%10 div 4,'percent','fixed_cart','fixed_product') from lab_c;
insert into wp_postmeta(post_id,meta_key,meta_value) select ID,'coupon_amount',5+n%40 from lab_c;
insert into wp_postmeta(post_id,meta_key,meta_value) select ID,'usage_limit',if(n%3=0,1,0) from lab_c;
insert into wp_postmeta(post_id,meta_key,meta_value) select ID,'usage_count',(n*n)%17 from lab_c;
insert into wp_postmeta(post_id,meta_key,meta_value) select ID,'individual_use',if(n%4=0,'yes','no') from lab_c;
insert into wp_postmeta(post_id,meta_key,meta_value) select ID,'free_shipping','no' from lab_c;
insert into wp_postmeta(post_id,meta_key,meta_value) select ID,'date_expires',if(n%5=0,null,unix_timestamp('2026-06-01'+interval n%400 day)) from lab_c;
insert into wp_postmeta(post_id,meta_key,meta_value) select ID,'_used_by',1+n*11%48000 from lab_c where n%2=0;
insert into wp_postmeta(post_id,meta_key,meta_value) select ID,'_used_by',1+n*13%48000 from lab_c where n%4=0;
-- orders: statuses as in a working shop, order fields from invoice, shipping and ERP plugins
drop table if exists lab_o; create table lab_o (id bigint unsigned primary key, n int) select id,row_number() over(order by date_created_gmt desc) n from wp_wc_orders where type='shop_order';
update wp_wc_orders o join lab_o l on l.id=o.id set o.status=case when l.n<=900 and l.n%3<2 then 'wc-processing' when l.n%40=0 then 'wc-on-hold' when l.n%60=1 then 'wc-pending' when l.n%30=2 then 'wc-cancelled' when l.n%50=3 then 'wc-refunded' when l.n%90=4 then 'wc-failed' else 'wc-completed' end;
insert into wp_wc_orders_meta(order_id,meta_key,meta_value) select id,'_invoice_number',concat('INV-2026-',lpad(48200-n,6,'0')) from lab_o where n%12>0;
insert into wp_wc_orders_meta(order_id,meta_key,meta_value) select id,'_tracking_number',concat('TRK',lpad(n*7919,12,'0')) from lab_o where n%10<8;
insert into wp_wc_orders_meta(order_id,meta_key,meta_value) select id,'_erp_order_id',concat('SO',100000+n) from lab_o;
insert into wp_wc_orders_meta(order_id,meta_key,meta_value) select id,'_delivery_date',date_format('2026-12-05'-interval n div 130 day,'%Y-%m-%d') from lab_o where n%3=0;
-- customers
insert into wp_usermeta(user_id,meta_key,meta_value) select user_id,'wc_money_spent_wp',round(meta_value*37.5+user_id%90,2) from wp_usermeta where meta_key='wc_order_count_wp';
insert into wp_usermeta(user_id,meta_key,meta_value) select ID,'_vip','yes' from wp_users where ID%31=0;
insert into wp_usermeta(user_id,meta_key,meta_value) select ID,'billing_vat',concat('TR',ID*7919) from wp_users where ID%9=0;
-- 12,000 orders stored as posts, for a shop that has not moved to HPOS
insert into wp_posts(post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,comment_status,ping_status,post_name,to_ping,pinged,post_modified,post_modified_gmt,post_content_filtered,guid,post_type) select 1,'2026-11-24'-interval n*23 minute,'2026-11-24'-interval n*23 minute,'','Order','',case when n<=250 and n%3<2 then 'wc-processing' when n%40=0 then 'wc-on-hold' when n%60=1 then 'wc-pending' when n%30=2 then 'wc-cancelled' else 'wc-completed' end,'closed','closed',concat('legacy-order-',n),'','','2026-11-24','2026-11-24','','','shop_order' from lab_n where n<=12000;
drop table if exists lab_lo; create table lab_lo (ID bigint unsigned primary key, n int, uid bigint unsigned) select ID,cast(substr(post_name,14) as unsigned) n,0 uid from wp_posts where post_type='shop_order';
drop table if exists lab_u; create table lab_u (rn int primary key, ID bigint unsigned) select row_number() over(order by ID) rn,ID from wp_users where ID>1;
update lab_lo l join lab_u u on u.rn=1+(l.n*l.n+l.n*31)%9000 set l.uid=if(l.n%5=0,0,u.ID);
drop table if exists lab_k; create table lab_k (k varchar(60), src varchar(60), v varchar(60));
insert into lab_k values('_billing_first_name','billing_first_name',''),('_billing_last_name','billing_last_name',''),('_billing_company','billing_company',''),('_billing_city','billing_city',''),('_billing_postcode','billing_postcode',''),('_billing_country','billing_country',''),('_billing_state','billing_state',''),('_billing_email','billing_email',''),('_billing_phone','billing_phone',''),('_shipping_first_name','billing_first_name',''),('_shipping_last_name','billing_last_name',''),('_shipping_city','billing_city',''),('_shipping_postcode','billing_postcode',''),('_shipping_country','billing_country','');
insert into wp_postmeta(post_id,meta_key,meta_value) select l.ID,k.k,coalesce(m.meta_value,'') from lab_lo l join lab_u u on u.rn=1+(l.n*l.n+l.n*31)%9000 join lab_k k left join wp_usermeta m on m.user_id=u.ID and m.meta_key=k.src;
truncate lab_k; insert into lab_k values('_payment_method','','cod'),('_payment_method_title','','Cash on delivery'),('_created_via','','checkout'),('_order_currency','','EUR'),('_cart_discount','','0'),('_cart_discount_tax','','0'),('_order_shipping','','4.90'),('_order_shipping_tax','','0'),('_order_tax','','0'),('_order_version','','11.3.1'),('_prices_include_tax','','no'),('_billing_address_1','','Main street 1'),('_billing_address_2','',''),('_shipping_address_1','','Main street 1'),('_shipping_company','',''),('_shipping_state','',''),('_recorded_sales','','yes'),('_recorded_coupon_usage_counts','','yes'),('_order_stock_reduced','','yes'),('_download_permissions_granted','','yes'),('_customer_ip_address','','127.0.0.1'),('_customer_user_agent','','Mozilla/5.0');
insert into wp_postmeta(post_id,meta_key,meta_value) select l.ID,k.k,k.v from lab_lo l join lab_k k;
insert into wp_postmeta(post_id,meta_key,meta_value) select ID,'_customer_user',uid from lab_lo;
insert into wp_postmeta(post_id,meta_key,meta_value) select ID,'_order_total',20+(n*37)%400 from lab_lo;
insert into wp_postmeta(post_id,meta_key,meta_value) select ID,'_order_key',concat('wc_order_',sha2(n,256)) from lab_lo;
insert into wp_postmeta(post_id,meta_key,meta_value) select ID,'_date_paid',unix_timestamp('2026-11-24'-interval n*23 minute) from lab_lo;
insert into wp_postmeta(post_id,meta_key,meta_value) select ID,'_invoice_number',concat('LINV-',lpad(n,6,'0')) from lab_lo where n%12>0;
insert into wp_postmeta(post_id,meta_key,meta_value) select ID,'_tracking_number',concat('LTRK',lpad(n*7919,12,'0')) from lab_lo where n%10<8;
insert into wp_postmeta(post_id,meta_key,meta_value) select l.ID,'_billing_address_index',group_concat(m.meta_value order by m.meta_id separator ' ') from lab_lo l join wp_postmeta m on m.post_id=l.ID and m.meta_key in('_billing_first_name','_billing_last_name','_billing_company','_billing_city','_billing_postcode','_billing_country','_billing_email','_billing_phone') group by l.ID;
insert into wp_postmeta(post_id,meta_key,meta_value) select l.ID,'_shipping_address_index',group_concat(m.meta_value order by m.meta_id separator ' ') from lab_lo l join wp_postmeta m on m.post_id=l.ID and m.meta_key in('_shipping_first_name','_shipping_last_name','_shipping_city','_shipping_postcode','_shipping_country') group by l.ID;
drop table if exists lab_n,lab_p,lab_c,lab_o,lab_u,lab_k,lab_lo;
analyze table wp_posts,wp_postmeta,wp_usermeta,wp_wc_orders,wp_wc_orders_meta,wp_wc_product_meta_lookup;
