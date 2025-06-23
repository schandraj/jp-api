<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Benefit;
use App\Models\Course;
use App\Models\CourseCrossSell;
use App\Models\CourseQuestion;
use App\Models\CourseTopic;
use App\Models\QuestionAnswer;
use App\Models\TopicLesson;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class CourseController extends Controller
{
    /**
     * Store a newly created course.
     */
    public function store(Request $request)
    {
        // Log raw input for debugging
        \Log::info('Store course request:', $request->all());

        // Decode JSON strings for array fields
        $input = $request->all();
        $jsonFields = ['topic', 'cross_sell', 'benefits', 'questions', 'benefits', 'studied'];
        foreach ($jsonFields as $field) {
            if ($request->has($field)) {
                $input[$field] = json_decode($request->input($field), true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return response()->json([$field => "Invalid JSON format for $field"], 422);
                }
            } else {
                $input[$field] = []; // Default to empty array if not provided
            }
        }

        // Validate the input
        $validator = Validator::make($input, [
            'title' => 'required|string|max:255',
            'category_id' => 'required|exists:categories,id',
            'course_level' => 'required|in:BEGINNER,INTERMEDIATE,ADVANCE,EXPERT',
            'max_student' => 'required|integer|min:1',
            'is_public' => 'nullable|boolean',
            'short_description' => 'required|string',
            'description' => 'nullable|string',
            'thumbnail' => 'required|image|mimes:jpeg,png,jpg|max:2048',
            'poster' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'link_ebook' => 'nullable|url',
            'link_group' => 'nullable|string',
            'slug' => 'nullable|string|unique:courses,slug',
            'price' => 'required|numeric|min:0',
            'discount_type' => 'nullable|in:PERCENTAGE,NOMINAL',
            'discount' => 'nullable|numeric|min:0',
            'type' => 'required|in:Course,Live_Teaching,CBT',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'duration' => 'nullable|integer|min:0',
            'topic' => 'nullable|array',
            'topic.*.name' => 'required_with:topic|string',
            'topic.*.lesson' => 'required_with:topic|array',
            'topic.*.lesson.*.name' => 'required_with:topic.*.lesson|string',
            'topic.*.lesson.*.video_link' => 'nullable|url',
            'topic.*.lesson.*.description' => 'nullable|string',
            'topic.*.lesson.*.is_premium' => 'required_with:topic.*.lesson|boolean',
            'cross_sell' => 'nullable|array',
            'cross_sell.*.cross_course_id' => 'required_with:cross_sell|exists:courses,id',
            'cross_sell.*.note' => 'nullable|string',
            'benefits' => 'nullable|array',
            'benefits.*.id' => 'nullable|exists:benefits,id',
            'benefits.*.name' => 'required_with:benefits|string|max:255',
            'benefits.*.icon' => 'required_with:benefits|string|max:255',
            'questions' => 'nullable|array',
            'questions.*.question' => 'required_with:questions|string',
            'questions.*.discussion' => 'required_with:questions|string',
            'questions.*.answers' => 'required_with:questions|array|min:1',
            'questions.*.answers.*.choice' => 'required_with:questions.*.answers|string',
            'questions.*.answers.*.is_true' => 'required_with:questions.*.answers|boolean',
            'studied' => 'nullable|array',
            'studied.*' => 'string',
        ]);

        if ($validator->fails()) {
            \Log::error('Validation errors:', $validator->errors()->toArray());
            return response()->json($validator->errors(), 422);
        }

        try {
            DB::beginTransaction();

            // Handle file uploads
            $thumbnailPath = $request->file('thumbnail')->store('thumbnails', 'public');
            $posterPath = $request->hasFile('poster') ? $request->file('poster')->store('posters', 'public') : null;

            // Create course
            $course = Course::create([
                'title' => $input['title'],
                'category_id' => $input['category_id'],
                'course_level' => $input['course_level'],
                'max_student' => $input['max_student'],
                'is_public' => $input['is_public'] ?? false,
                'short_description' => $input['short_description'],
                'description' => $input['description'] ?? null,
                'image' => $thumbnailPath,
                'poster' => $posterPath,
                'link_ebook' => $input['link_ebook'] ?? null,
                'link_group' => $input['link_group'] ?? null,
                'slug' => $input['slug'],
                'price' => $input['price'],
                'discount_type' => $input['discount_type'] ?? null,
                'discount' => $input['discount'] ?? null,
                'status' => $input['status'] ?? 'UNPUBLISHED',
                'type' => $input['type'],
                'start_date' => $input['start_date'] ?? null,
                'end_date' => $input['end_date'] ?? null,
                'duration' => $input['duration'] ?? null,
                'studied'  => $input['studied'] ?? null,
            ]);

            // Create topics and lessons
            foreach ($input['topic'] as $topicData) {
                $topic = CourseTopic::create([
                    'course_id' => $course->id,
                    'name' => $topicData['name'],
                ]);

                foreach ($topicData['lesson'] as $lessonData) {
                    TopicLesson::create([
                        'topic_id' => $topic->id,
                        'name' => $lessonData['name'],
                        'video_link' => $lessonData['video_link'] ?? null,
                        'description' => $lessonData['description'] ?? null,
                        'is_premium' => $lessonData['is_premium'],
                    ]);
                }
            }

            // Create questions and answers
            foreach ($input['questions'] as $questionData) {
                $question = CourseQuestion::create([
                    'course_id' => $course->id,
                    'question' => $questionData['question'],
                    'discussion' => $questionData['discussion'],
                ]);

                foreach ($questionData['answers'] as $answerData) {
                    QuestionAnswer::create([
                        'question_id' => $question->id,
                        'choice' => $answerData['choice'],
                        'is_true' => $answerData['is_true'],
                    ]);
                }
            }

            // Create cross-sells
            if (!empty($input['cross_sell'])) {
                foreach ($input['cross_sell'] as $crossSellData) {
                    CourseCrossSell::create([
                        'course_id' => $course->id,
                        'cross_course_id' => $crossSellData['cross_course_id'],
                        'note' => $crossSellData['note'] ?? null,
                    ]);
                }
            }

            // Create and attach benefits
            if (!empty($input['benefits'])) {
                $benefitIds = [];
                foreach ($input['benefits'] as $benefitData) {
                    if (isset($benefitData['id'])) {
                        $benefitIds[] = $benefitData['id'];
                    } else {
                        $benefit = Benefit::create([
                            'name' => $benefitData['name'],
                            'icon' => $benefitData['icon'],
                        ]);
                        $benefitIds[] = $benefit->id;
                    }
                }
                $course->benefits()->sync($benefitIds);
            }

            DB::commit();

            return response()->json([
                'message' => 'Course created successfully',
                'data' => $course->load(['topics.lessons', 'crossSells', 'benefits', 'questions.answers']),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            if (isset($thumbnailPath)) {
                Storage::disk('public')->delete($thumbnailPath);
            }
            if (isset($posterPath)) {
                Storage::disk('public')->delete($posterPath);
            }
            \Log::error('Course creation failed:', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Failed to create course: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Display a listing of courses with pagination and custom limit.
     */
    public function index(Request $request)
    {
        try {
            // Get query parameters with defaults
            $limit = $request->input('limit', 10);
            $page = $request->input('page', 1);
            $type = $request->input('type');
            $category = $request->input('category');
            $level = $request->input('level');
            $search = $request->input('search');
            $isFree = $request->input('is_free', 'false'); // Default to 'false'

            // Validate limit and page
            $validator = Validator::make(['limit' => $limit, 'page' => $page], [
                'limit' => 'integer|min:1|max:100',
                'page' => 'integer|min:1',
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            // Build the query
            $query = Course::query();

            // Apply filters
            if ($type) {
                $query->where('type', $type);
            }
            if ($category) {
                $query->where('category_id', $category);
            }
            if ($level) {
                $query->where('course_level', $level);
            }
            if ($search) {
                $query->where('title', 'like', "%{$search}%");
            }
            if ($isFree === 'true') {
                $query->where('price', 0);
            }

            // Paginate the results
            $courses = $query->paginate($limit, ['*'], 'page', $page);

            // Return paginated response
            return response()->json([
                'message' => 'Courses retrieved successfully',
                'data' => $courses->items(),
                'pagination' => [
                    'total' => $courses->total(),
                    'per_page' => $courses->perPage(),
                    'current_page' => $courses->currentPage(),
                    'last_page' => $courses->lastPage(),
                    'from' => $courses->firstItem(),
                    'to' => $courses->lastItem(),
                    'next_page_url' => $courses->nextPageUrl(),
                    'prev_page_url' => $courses->previousPageUrl(),
                ],
            ], 200);
        } catch (\Exception $e) {
            \Log::error('Failed to retrieve courses:', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Failed to retrieve courses: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified course.
     */
    public function show($id)
    {
        try {
            $course = Course::with(['category', 'topics.lessons', 'crossSells', 'benefits'])
                ->findOrFail($id);
            return response()->json([
                'message' => 'Course retrieved successfully',
                'data' => $course
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Course not found'], 404);
        }
    }

    /**
     * Display the course by slug.
     */
    public function showBySlug($slug)
    {
        try {
            $course = Course::with(['category', 'topics.lessons', 'crossSells', 'benefits', 'questions.answers'])
                ->where('slug', $slug)
                ->firstOrFail();
            return response()->json([
                'message' => 'Course retrieved successfully',
                'data' => $course
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Course not found'], 404);
        }
    }

    /**
     * Display the course by title.
     */
    public function showByTitle($title)
    {
        try {
            $course = Course::with(['category', 'topics.lessons', 'crossSells', 'benefits', 'questions.answers'])
                ->where('title', 'like', "%$title%") // Partial match for flexibility
                ->firstOrFail();
            return response()->json([
                'message' => 'Course retrieved successfully',
                'data' => $course
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Course not found'], 404);
        }
    }

    /**
     * Update an existing course.
     */
    public function update(Request $request, $id)
    {
        // Log raw input for debugging
        \Log::info('Update course request:', $request->all());

        // Decode JSON strings for array fields
        $input = $request->all();
        $jsonFields = ['topic', 'cross_sell', 'benefits', 'questions', 'studied'];
        foreach ($jsonFields as $field) {
            if ($request->has($field)) {
                $input[$field] = json_decode($request->input($field), true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return response()->json([$field => "Invalid JSON format for $field"], 422);
                }
            } else {
                $input[$field] = []; // Default to empty array if not provided
            }
        }

        // Validate the input
        $validator = Validator::make($input, [
            'title' => 'sometimes|required|string|max:255',
            'category_id' => 'sometimes|required|exists:categories,id',
            'course_level' => 'sometimes|required|in:BEGINNER,INTERMEDIATE,ADVANCE,EXPERT',
            'max_student' => 'sometimes|required|integer|min:1',
            'is_public' => 'sometimes|boolean',
            'short_description' => 'sometimes|required|string',
            'description' => 'nullable|string',
            'thumbnail' => 'sometimes|image|mimes:jpeg,png,jpg|max:2048',
            'poster' => 'sometimes|image|mimes:jpeg,png,jpg|max:2048',
            'link_ebook' => 'nullable|url',
            'link_group' => 'nullable|string',
            'slug' => 'nullable|string|unique:courses,slug,' . $id,
            'price' => 'sometimes|required|numeric|min:0',
            'discount_type' => 'nullable|in:PERCENTAGE,NOMINAL',
            'discount' => 'nullable|numeric|min:0',
            'type' => 'sometimes|required|in:Course,Live_Teaching,CBT',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'duration' => 'nullable|integer|min:0',
            'status' => 'sometimes|required|in:DRAFT,UNPUBLISHED,PUBLISHED',
            'topic' => 'sometimes|array',
            'topic.*.name' => 'required_with:topic|string',
            'topic.*.lesson' => 'required_with:topic|array',
            'topic.*.lesson.*.name' => 'required_with:topic.*.lesson|string',
            'topic.*.lesson.*.video_link' => 'nullable|url',
            'topic.*.lesson.*.description' => 'nullable|string',
            'topic.*.lesson.*.is_premium' => 'required_with:topic.*.lesson|boolean',
            'cross_sell' => 'sometimes|array',
            'cross_sell.*.cross_course_id' => 'required_with:cross_sell|exists:courses,id',
            'cross_sell.*.note' => 'nullable|string',
            'benefits' => 'sometimes|array',
            'benefits.*.id' => 'nullable|exists:benefits,id',
            'benefits.*.name' => 'required_without:benefits.*.id|string|max:255',
            'benefits.*.icon' => 'required_without:benefits.*.icon|string|max:255',
            'questions' => 'sometimes|array',
            'questions.*.question' => 'required_with:questions|string',
            'questions.*.discussion' => 'required_with:questions|string',
            'questions.*.answers' => 'required_with:questions|array|min:1',
            'questions.*.answers.*.choice' => 'required_with:questions.*.answers|string',
            'questions.*.answers.*.is_true' => 'required_with:questions.*.answers|boolean',
            'studied' => 'sometimes|array',
            'studied.*' => 'string',
        ]);

        if ($validator->fails()) {
            \Log::error('Validation errors:', $validator->errors()->toArray());
            return response()->json($validator->errors(), 422);
        }

        try {
            DB::beginTransaction();

            $course = Course::findOrFail($id);
            $oldThumbnailPath = $course->thumbnail;
            $oldPosterPath = $course->poster;

            // Handle file uploads
            $thumbnailPath = $oldThumbnailPath;
            if ($request->hasFile('thumbnail')) {
                $thumbnailPath = $request->file('thumbnail')->store('thumbnails', 'public');
            }
            $posterPath = $oldPosterPath;
            if ($request->hasFile('poster')) {
                $posterPath = $request->file('poster')->store('posters', 'public');
            }

            // Update course
            $course->update([
                'title' => $input['title'] ?? $course->title,
                'category_id' => $input['category_id'] ?? $course->category_id,
                'course_level' => $input['course_level'] ?? $course->course_level,
                'max_student' => $input['max_student'] ?? $course->max_student,
                'is_public' => $input['is_public'] ?? $course->is_public,
                'short_description' => $input['short_description'] ?? $course->short_description,
                'description' => $input['description'] ?? $course->description,
                'image' => $thumbnailPath,
                'poster' => $posterPath,
                'link_ebook' => $input['link_ebook'] ?? $course->link_ebook,
                'link_group' => $input['link_group'] ?? $course->link_group,
                'slug' => $input['slug'] ?? $course->slug,
                'price' => $input['price'] ?? $course->price,
                'discount_type' => $input['discount_type'] ?? $course->discount_type,
                'discount' => $input['discount'] ?? $course->discount,
                'type' => $input['type'] ?? $course->type,
                'start_date' => $input['start_date'] ?? $course->start_date,
                'end_date' => $input['end_date'] ?? $course->end_date,
                'duration' => $input['duration'] ?? $course->duration,
                'status' => $input['status'] ?? $course->status,
                'studied' => $input['studied'] ?? $course->studied,
            ]);

            // Delete old images if new ones were uploaded
            if ($request->hasFile('thumbnail') && $oldThumbnailPath && $oldThumbnailPath !== $thumbnailPath) {
                Storage::disk('public')->delete($oldThumbnailPath);
            }
            if ($request->hasFile('poster') && $oldPosterPath && $oldPosterPath !== $posterPath) {
                Storage::disk('public')->delete($oldPosterPath);
            }

            // Update topics and lessons (replace existing)
            if (isset($input['topic']) && !empty($input['topic'])) {
                $course->topics()->delete(); // Cascades to lessons
                foreach ($input['topic'] as $topicData) {
                    $topic = CourseTopic::create([
                        'course_id' => $course->id,
                        'name' => $topicData['name'],
                    ]);
                    foreach ($topicData['lesson'] as $lessonData) {
                        TopicLesson::create([
                            'topic_id' => $topic->id,
                            'name' => $lessonData['name'],
                            'video_link' => $lessonData['video_link'] ?? null,
                            'description' => $lessonData['description'] ?? null,
                            'is_premium' => $lessonData['is_premium'],
                        ]);
                    }
                }
            }

            // Update cross-sells (replace existing)
            if (isset($input['cross_sell']) && !empty($input['cross_sell'])) {
                $course->crossSells()->delete();
                foreach ($input['cross_sell'] as $crossSellData) {
                    CourseCrossSell::create([
                        'course_id' => $course->id,
                        'cross_course_id' => $crossSellData['cross_course_id'],
                        'note' => $crossSellData['note'] ?? null,
                    ]);
                }
            }

            // Update benefits (sync or create new)
            if (isset($input['benefits']) && !empty($input['benefits'])) {
                $benefitIds = [];
                foreach ($input['benefits'] as $benefitData) {
                    if (isset($benefitData['id'])) {
                        $benefitIds[] = $benefitData['id'];
                    } else {
                        $benefit = Benefit::create([
                            'name' => $benefitData['name'],
                            'icon' => $benefitData['icon'],
                        ]);
                        $benefitIds[] = $benefit->id;
                    }
                }
                $course->benefits()->sync($benefitIds);
            }

            // Update questions and answers (replace existing)
            if (isset($input['questions']) && !empty($input['questions'])) {
                $course->questions()->delete(); // Cascades to answers
                foreach ($input['questions'] as $questionData) {
                    $question = CourseQuestion::create([
                        'course_id' => $course->id,
                        'question' => $questionData['question'],
                        'discussion' => $questionData['discussion'],
                    ]);
                    foreach ($questionData['answers'] as $answerData) {
                        QuestionAnswer::create([
                            'question_id' => $question->id,
                            'choice' => $answerData['choice'],
                            'is_true' => $answerData['is_true'],
                        ]);
                    }
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Course updated successfully',
                'data' => $course->load(['topics.lessons', 'crossSells', 'benefits', 'questions.answers']),
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            if ($request->hasFile('thumbnail') && $thumbnailPath && $thumbnailPath !== $oldThumbnailPath) {
                Storage::disk('public')->delete($thumbnailPath);
            }
            if ($request->hasFile('poster') && $posterPath && $posterPath !== $oldPosterPath) {
                Storage::disk('public')->delete($posterPath);
            }
            \Log::error('Course update failed:', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Failed to update course: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Delete a course.
     */
    public function destroy($id)
    {
        try {
            $course = Course::findOrFail($id);
            $imagePath = $course->image;

            // Delete the course (cascades to topics, lessons, cross-sells, and benefits)
            $course->delete();

            // Delete the associated image
            if ($imagePath) {
                Storage::disk('public')->delete($imagePath);
            }

            return response()->json(['message' => 'Course deleted successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to delete course: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Publish a course.
     */
    public function publish(Request $request, $id)
    {
        try {
            $course = Course::findOrFail($id);

            DB::beginTransaction();

            $course->update([
                'status' => 'PUBLISHED',
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Course published successfully',
                'data' => $course
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to publish course: ' . $e->getMessage()], 500);
        }
    }
}
