create temporary table l as select ID from wp_posts where post_type='listing';
insert into wp_postmeta(post_id,meta_key,meta_value) select ID,f,concat('field_',left(sha2(f,256),13)) from l,(select '_price' f union select '_bedrooms' union select '_area' union select '_city' union select '_available_from' union select '_status') f where not exists (select 1 from wp_postmeta m where m.post_id=l.ID and m.meta_key='_bedrooms');
drop table if exists lab_n;
analyze table wp_posts,wp_postmeta,wp_users,wp_usermeta,wp_comments,wp_commentmeta,wp_wc_orders,wp_wc_orders_meta,wp_woocommerce_order_items,wp_woocommerce_order_itemmeta,wp_terms,wp_termmeta,wp_term_relationships,wp_wc_product_meta_lookup,wp_wc_order_stats;
