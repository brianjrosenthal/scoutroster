-- A section may play several music tracks in turn: music_cues holds a JSON
-- list of {"track_id": N, "start_index": I} (0-based photo index; the first
-- cue always starts at 0). When set it overrides track_id, which is kept in
-- sync with the first cue so the single-track dropdown still reads right.

ALTER TABLE slideshow_sections
ADD COLUMN music_cues TEXT DEFAULT NULL
COMMENT 'JSON [{track_id,start_index}] when the section uses several tracks'
AFTER track_id;
