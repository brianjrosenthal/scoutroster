-- Add 'parent' as a valid relationship type for parent_relationships
ALTER TABLE parent_relationships
  MODIFY relationship ENUM('father','mother','guardian','parent') NOT NULL;
