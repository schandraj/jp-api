<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
        $jsonFields = ['topic', 'slug', 'cross_sell', 'benefits', 'questions'];
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
            'slug' => 'nullable|array',
            'slug.*' => 'string',
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
            'benefits.*.benefit_id' => 'required_with:benefits|exists:benefits,id',
            'questions' => 'nullable|array',
            'questions.*.question' => 'required_with:questions|string',
            'questions.*.discussion' => 'required_with:questions|string',
            'questions.*.answers' => 'required_with:questions|array|min:1',
            'questions.*.answers.*.choice' => 'required_with:questions.*.answers|string',
            'questions.*.answers.*.is_true' => 'required_with:questions.*.answers|boolean',
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
                'thumbnail' => $thumbnailPath,
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

            // Sync benefits
            if (!empty($input['benefits'])) {
                $benefitIds = array_column($input['benefits'], 'benefit_id');
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
            // Validate the limit and type parameters
            $validator = Validator::make($request->all(), [
                'limit' => 'sometimes|integer|min:1|max:100',
                'type' => 'nullable|in:Course,Live_Teaching,CBT',
            ]);

            if ($validator->fails()) {
                \Log::error('Validation errors:', $validator->errors()->toArray());
                return response()->json($validator->errors(), 422);
            }

            // Set default limit if not provided
            $limit = $request->input('limit', 10);

            // Build query
            $query = Course::with(['category', 'topics.lessons', 'crossSells', 'benefits', 'questions.answers']);

            // Apply type filter if provided
            if ($request->has('type')) {
                $query->where('type', $request->type);
            }

            // Paginate results
            $courses = $query->paginate($limit);

            return response()->json([
                'message' => 'Courses retrieved successfully',
                'data' => $courses
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
     * Update an existing course.
     */
    public function update(Request $request, $id)
    {
        // Decode JSON strings for array fields
        $input = $request->all();
        if ($request->has('topic')) {
            $input['topic'] = json_decode($request->input('topic'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return response()->json(['topic' => 'Invalid JSON format for topic'], 422);
            }
        }
        if ($request->has('slug')) {
            $input['slug'] = json_decode($request->input('slug'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return response()->json(['slug' => 'Invalid JSON format for slug'], 422);
            }
        }
        if ($request->has('cross_sell')) {
            $input['cross_sell'] = json_decode($request->input('cross_sell'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return response()->json(['cross_sell' => 'Invalid JSON format for cross_sell'], 422);
            }
        }
        if ($request->has('benefits')) {
            $input['benefits'] = json_decode($request->input('benefits'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return response()->json(['benefits' => 'Invalid JSON format for benefits'], 422);
            }
        }

        // Validate the input (all fields optional except required ones)
        $validator = Validator::make($input, [
            'title' => 'sometimes|required|string|max:255',
            'category_id' => 'sometimes|required|exists:categories,id',
            'course_level' => 'sometimes|required|in:BEGINNER,INTERMEDIATE,ADVANCE,EXPERT',
            'max_student' => 'sometimes|required|integer|min:1',
            'is_public' => 'sometimes|required|boolean',
            'short_description' => 'sometimes|required|string',
            'description' => 'nullable|string',
            'thumbnail' => 'sometimes|required|image|mimes:jpeg,png,jpg|max:2048',
            'link_ebook' => 'nullable|url',
            'link_group' => 'nullable|string',
            'slug' => 'sometimes|required|array',
            'slug.*' => 'string',
            'price' => 'sometimes|required|numeric|min:0',
            'discount_type' => 'nullable|in:PERCENTAGE,NOMINAL',
            'discount' => 'nullable|numeric|min:0',
            'topic' => 'sometimes|required|array',
            'topic.*.name' => 'required|string',
            'topic.*.lesson' => 'required|array',
            'topic.*.lesson.*.name' => 'required|string',
            'topic.*.lesson.*.video_link' => 'nullable|url',
            'topic.*.lesson.*.description' => 'nullable|string',
            'topic.*.lesson.*.is_premium' => 'required|boolean',
            'cross_sell' => 'nullable|array',
            'cross_sell.*.cross_course_id' => 'required|exists:courses,id',
            'cross_sell.*.note' => 'nullable|string',
            'benefits' => 'nullable|array',
            'benefits.*.benefit_id' => 'required|exists:benefits,id',
            'status' => 'sometimes|required|in:DRAFT,UNPUBLISHED,PUBLISHED',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        try {
            DB::beginTransaction();

            $course = Course::findOrFail($id);
            $oldImagePath = $course->image;

            // Handle file upload if provided
            $imagePath = $oldImagePath;
            if ($request->hasFile('thumbnail')) {
                $imagePath = $request->file('thumbnail')->store('thumbnails', 'public');
            }

            // Update course
            $course->update([
                'title' => $input['title'] ?? $course->title,
                'category_id' => $input['category_id'] ?? $course->category_id,
                'course_level' => $input['course_level'] ?? $course->course_level,
                'max_student' => $input['max_student'] ?? $course->max_student,
                'is_public' => isset($input['is_public']) ? $input['is_public'] : $course->is_public,
                'short_description' => $input['short_description'] ?? $course->short_description,
                'description' => $input['description'] ?? $course->description,
                'image' => $imagePath,
                'link_ebook' => $input['link_ebook'] ?? $course->link_ebook,
                'link_group' => $input['link_group'] ?? $course->link_group,
                'slug' => $input['slug'] ?? $course->slug,
                'price' => $input['price'] ?? $course->price,
                'discount_type' => $input['discount_type'] ?? $course->discount_type,
                'discount' => $input['discount'] ?? $course->discount,
            ]);

            // Delete old image if a new one was uploaded
            if ($request->hasFile('thumbnail') && $oldImagePath && $oldImagePath !== $imagePath) {
                Storage::disk('public')->delete($oldImagePath);
            }

            // Update topics and lessons (replace existing)
            if (isset($input['topic'])) {
                // Delete existing topics (cascades to lessons due to onDelete('cascade'))
                $course->topics()->delete();

                foreach ($input['topic'] as $topicData) {
                    $topic = CourseTopic::create([
                        'course_id' => $course->id,
                        'name' => $topicData['name'],
                    ]);

                    foreach ($topicData['lesson'] as $lessonData) {
                        TopicLesson::create([
                            'topic_id' => $topic->id,
                            'name' => $lessonData['name'],
                            'video_link' => $lessonData['video_link'],
                            'description' => $lessonData['description'],
                            'is_premium' => $lessonData['is_premium'],
                        ]);
                    }
                }
            }

            // Update cross-sells (replace existing)
            if (isset($input['cross_sell'])) {
                // Delete existing cross-sells
                $course->crossSells()->delete();

                foreach ($input['cross_sell'] as $crossSellData) {
                    CourseCrossSell::create([
                        'course_id' => $course->id,
                        'cross_course_id' => $crossSellData['cross_course_id'],
                        'note' => $crossSellData['note'],
                    ]);
                }
            }

            // Sync benefits
            if (isset($input['benefits'])) {
                $benefitIds = array_column($input['benefits'], 'benefit_id');
                $course->benefits()->sync($benefitIds);
            }

            DB::commit();

            return response()->json([
                'message' => 'Course updated successfully',
                'data' => $course->load(['topics.lessons', 'crossSells', 'benefits']),
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            if (isset($imagePath) && $imagePath !== $oldImagePath) {
                Storage::disk('public')->delete($imagePath);
            }
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
