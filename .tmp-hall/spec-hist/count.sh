#!/usr/bin/env bash
# Загрузить каждый исторический бандл в отдельную схему и посчитать
# ограничения по information_schema — то есть тем же способом, каким их
# считает verify-live.php, а не своим парсером.
set -u
for f in cd9323d8 da0c75fc c84e8688 c581afbd; do
  db="nbl_h_$f"
  docker exec nabilet-mysql mysql -uroot -prootpass -e \
    "DROP DATABASE IF EXISTS \`$db\`; CREATE DATABASE \`$db\` CHARACTER SET utf8mb4;" 2>/dev/null
  docker exec -i nabilet-mysql mysql -uroot -prootpass "$db" < ".tmp-hall/spec-hist/$f.sql" 2>/dev/null
  echo -n "$f  "
  docker exec nabilet-mysql mysql -uroot -prootpass -N -B -e "
    SELECT
      (SELECT count(*) FROM information_schema.table_constraints WHERE constraint_schema='$db' AND constraint_type='FOREIGN KEY'),
      (SELECT count(*) FROM information_schema.table_constraints WHERE constraint_schema='$db' AND constraint_type='UNIQUE'),
      (SELECT count(*) FROM information_schema.table_constraints WHERE constraint_schema='$db' AND constraint_type='CHECK'),
      (SELECT count(*) FROM information_schema.tables WHERE table_schema='$db'),
      (SELECT count(*) FROM information_schema.columns WHERE table_schema='$db');" 2>/dev/null
done
