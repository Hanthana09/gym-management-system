# setly-phase-exercise-media.md §4: the dataset is vendored once, offline
# — never fetched by the running app. Pinned commit kept in sync with
# ImportExercisesCommand::PINNED_SOURCE_COMMIT; bump both together.
EXERCISE_DB_COMMIT := b0eed061e1c832b3ed815fbaa4b45b3cdc14df49
EXERCISE_DB_DIR := var/vendor-data/free-exercise-db

.PHONY: vendor-exercise-data
vendor-exercise-data:
	rm -rf $(EXERCISE_DB_DIR)
	mkdir -p $(EXERCISE_DB_DIR)
	git clone https://github.com/yuhonas/free-exercise-db.git $(EXERCISE_DB_DIR)
	git -C $(EXERCISE_DB_DIR) checkout $(EXERCISE_DB_COMMIT)
