-- Add dietary preferences columns to users table
ALTER TABLE users 
ADD COLUMN dietary_vegetarian TINYINT(1) NOT NULL DEFAULT 0,
ADD COLUMN dietary_vegan TINYINT(1) NOT NULL DEFAULT 0,
ADD COLUMN dietary_lactose_free TINYINT(1) NOT NULL DEFAULT 0,
ADD COLUMN dietary_no_pork_shellfish TINYINT(1) NOT NULL DEFAULT 0,
ADD COLUMN dietary_nut_allergy TINYINT(1) NOT NULL DEFAULT 0,
ADD COLUMN dietary_other TEXT DEFAULT NULL;
