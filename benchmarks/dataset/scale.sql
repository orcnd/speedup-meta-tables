set session cte_max_recursion_depth=200000;
create table lab_n (n int primary key); insert into lab_n with recursive r as (select 1 n union all select n+1 from r where n<50000) select n from r;
-- 400 copies of the generated customers and orders, with real key sets and values.
set @u=1000, @o=1000000, @i=10000, @c=1000;
insert into wp_users select ID+@u*n, concat(user_login,'_',n), user_pass, concat(user_nicename,'-',n), concat(n,'.',user_email), user_url, user_registered - interval n day, '', 0, display_name from wp_users, lab_n where ID>1 and ID<1000 and n<=400;
insert into wp_usermeta(user_id,meta_key,meta_value) select user_id+@u*n, meta_key, if(meta_key='billing_email',concat(n,'.',meta_value),meta_value) from wp_usermeta, lab_n where user_id between 2 and 999 and n<=400 and umeta_id<100000;
-- a few staff accounts, as on real sites
update wp_usermeta set meta_value='a:1:{s:6:"editor";b:1;}' where meta_key='wp_capabilities' and user_id%2000=17;
update wp_usermeta set meta_value='a:1:{s:6:"author";b:1;}' where meta_key='wp_capabilities' and user_id%1000=33;
create temporary table src_o as select * from wp_wc_orders where id<1000000;
create temporary table src_om as select * from wp_wc_orders_meta where order_id<1000000;
create temporary table src_oa as select * from wp_wc_order_addresses where order_id<1000000;
create temporary table src_od as select * from wp_wc_order_operational_data where order_id<1000000;
create temporary table src_i as select * from wp_woocommerce_order_items where order_item_id<10000;
create temporary table src_im as select * from wp_woocommerce_order_itemmeta where order_item_id<10000;
create temporary table src_s as select * from wp_wc_order_stats where order_id<1000000;
create temporary table src_p as select * from wp_wc_order_product_lookup where order_item_id<10000;
insert into wp_wc_orders select id+@o*n,status,currency,type,tax_amount,total_amount,if(customer_id>1,customer_id+@u*n,0),concat(n,'.',billing_email),date_created_gmt - interval n day,date_updated_gmt - interval n day,0,payment_method,payment_method_title,transaction_id,ip_address,user_agent,customer_note from src_o, lab_n where n<=400;
insert into wp_wc_orders_meta(order_id,meta_key,meta_value) select order_id+@o*n,meta_key,meta_value from src_om, lab_n where n<=400;
insert into wp_wc_order_addresses(order_id,address_type,first_name,last_name,company,address_1,address_2,city,state,postcode,country,email,phone) select order_id+@o*n,address_type,first_name,last_name,company,address_1,address_2,city,state,postcode,country,if(email is null,null,concat(n,'.',email)),phone from src_oa, lab_n where n<=400;
insert into wp_wc_order_operational_data(order_id,created_via,woocommerce_version,prices_include_tax,coupon_usages_are_counted,download_permission_granted,cart_hash,new_order_email_sent,order_key,order_stock_reduced,date_paid_gmt,date_completed_gmt,shipping_tax_amount,shipping_total_amount,discount_tax_amount,discount_total_amount,recorded_sales) select order_id+@o*n,created_via,woocommerce_version,prices_include_tax,coupon_usages_are_counted,download_permission_granted,cart_hash,new_order_email_sent,concat(order_key,n),order_stock_reduced,date_paid_gmt - interval n day,date_completed_gmt - interval n day,shipping_tax_amount,shipping_total_amount,discount_tax_amount,discount_total_amount,recorded_sales from src_od, lab_n where n<=400;
insert into wp_woocommerce_order_items select order_item_id+@i*n,order_item_name,order_item_type,order_id+@o*n from src_i, lab_n where n<=400;
insert into wp_woocommerce_order_itemmeta(order_item_id,meta_key,meta_value) select order_item_id+@i*n,meta_key,meta_value from src_im, lab_n where n<=400;
insert into wp_wc_order_stats select order_id+@o*n,0,date_created - interval n day,date_created_gmt - interval n day,date_paid - interval n day,date_completed - interval n day,num_items_sold,total_sales,tax_total,shipping_total,net_total,returning_customer,status,customer_id,payment_method from src_s, lab_n where n<=400;
insert into wp_wc_order_product_lookup select order_item_id+@i*n,order_id+@o*n,product_id,variation_id,customer_id,date_created - interval n day,product_qty,product_net_revenue,product_gross_revenue,coupon_amount,tax_amount,shipping_amount,shipping_tax_amount from src_p, lab_n where n<=400;
-- 50,000 reviews with a rating
insert into wp_comments(comment_post_ID,comment_author,comment_author_email,comment_author_url,comment_author_IP,comment_date,comment_date_gmt,comment_content,comment_approved,comment_agent,comment_type) select p.ID,concat('Buyer ',n),concat('b',n,'@example.test'),'','127.0.0.1','2026-11-01'-interval n*9 minute,'2026-11-01'-interval n*9 minute,'Works as described, arrived on time.',if(n%40=0,'0','1'),'','review' from lab_n join (select ID,row_number() over (order by ID) rn from wp_posts where post_type='product') p on p.rn=1+(n*7)%25000;
insert into wp_commentmeta(comment_id,meta_key,meta_value) select comment_ID,'rating',1+comment_ID%5 from wp_comments where comment_type='review';
-- 30,000 media files
insert into wp_posts(post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,comment_status,ping_status,post_name,to_ping,pinged,post_modified,post_modified_gmt,post_content_filtered,guid,post_type,post_mime_type) select 1,'2026-10-01'-interval n hour,'2026-10-01'-interval n hour,'',concat('photo ',n),'','inherit','closed','closed',concat('photo-',n),'','','2026-10-01','2026-10-01','',concat('http://127.0.0.1:8099/wp-content/uploads/2026/photo-',n,'.jpg'),'attachment','image/jpeg' from lab_n where n<=30000;
insert into wp_postmeta(post_id,meta_key,meta_value) select ID,'_wp_attached_file',concat('2026/',post_name,'.jpg') from wp_posts where post_type='attachment' and post_name like 'photo-%';
insert into wp_postmeta(post_id,meta_key,meta_value) select ID,'_wp_attachment_metadata',concat('a:5:{s:5:"width";i:1600;s:6:"height";i:1200;s:4:"file";s:20:"2026/',post_name,'.jpg";s:5:"sizes";a:6:{',repeat('s:9:"thumbnail";a:5:{s:4:"file";s:18:"x-150x150.jpg";s:5:"width";i:150;s:6:"height";i:150;s:9:"mime-type";s:10:"image/jpeg";s:8:"filesize";i:5000;}',6),'}}') from wp_posts where post_type='attachment' and post_name like 'photo-%';
-- 50,000 listings of a custom post type, as made with a custom fields plugin (value plus the "_field" reference row)
insert into wp_posts(post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,comment_status,ping_status,post_name,to_ping,pinged,post_modified,post_modified_gmt,post_content_filtered,guid,post_type) select 1,'2026-11-01'-interval n*11 minute,'2026-11-01'-interval n*11 minute,repeat('Bright flat near the park with a balcony. ',12),concat('Listing ',n),'','publish','closed','closed',concat('listing-',n),'','','2026-11-01','2026-11-01','',concat('http://127.0.0.1:8099/?l=',n),'listing' from lab_n;
create temporary table l as select ID,cast(substr(post_name,9) as unsigned) n from wp_posts where post_type='listing';
insert into wp_postmeta(post_id,meta_key,meta_value) select ID,'price',50000+(n*7919)%950000 from l;
insert into wp_postmeta(post_id,meta_key,meta_value) select ID,'bedrooms',1+(n*31)%6 from l;
insert into wp_postmeta(post_id,meta_key,meta_value) select ID,'area',30+(n*131)%270 from l;
insert into wp_postmeta(post_id,meta_key,meta_value) select ID,'city',elt(1+(n*17)%40,'Berlin','Hamburg','Munich','Cologne','Frankfurt','Stuttgart','Leipzig','Dortmund','Essen','Bremen','Dresden','Hanover','Nuremberg','Duisburg','Bochum','Bonn','Paris','Lyon','Nice','Lille','Madrid','Seville','Porto','Lisbon','Rome','Milan','Turin','Naples','Vienna','Graz','Zurich','Basel','Bern','Dubai','Abu Dhabi','Doha','Riyadh','Jeddah','Muscat','Manama') from l;
insert into wp_postmeta(post_id,meta_key,meta_value) select ID,'available_from',date_format('2026-01-01'+interval (n*37)%700 day,'%Y%m%d') from l;
insert into wp_postmeta(post_id,meta_key,meta_value) select ID,'status',elt(1+(n*13)%4,'sale','rent','sold','rented') from l;
insert into wp_postmeta(post_id,meta_key,meta_value) select ID,'featured','1' from l where n%50=0;
insert into wp_postmeta(post_id,meta_key,meta_value) select ID,'feature',elt(1+(n*k)%8,'balcony','garage','garden','pool','lift','cellar','sauna','terrace') from l,(select 1 k union select 3 union select 5) k;
insert into wp_postmeta(post_id,meta_key,meta_value) select ID,f,concat('field_',md5(f)) from l,(select '_price' f union select '_bedrooms' union select '_area' union select '_city' union select '_available_from' union select '_status') f;
drop table lab_n;
analyze table wp_posts,wp_postmeta,wp_users,wp_usermeta,wp_comments,wp_commentmeta,wp_wc_orders,wp_wc_orders_meta,wp_woocommerce_order_items,wp_woocommerce_order_itemmeta,wp_terms,wp_termmeta,wp_term_relationships,wp_term_taxonomy,wp_wc_product_meta_lookup,wp_wc_order_stats,wp_wc_order_product_lookup;
