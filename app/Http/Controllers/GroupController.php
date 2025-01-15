<?php

namespace App\Http\Controllers;

use App\Mail\SendTransferMail;
use App\Models\Group;
use App\Models\GroupInvite;
use App\Models\GroupMember;
use App\Models\GroupRequest;
use App\Models\Post;
use App\Http\Requests\StoreGroupRequest;
use App\Http\Requests\UpdateGroupRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class GroupController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    /**
     * @OA\Get(
     *     path="/api/group",
     *     operationId="UsergetGroupList",
     *     tags={"AdminGroup"},
     *     summary="Get list of Groups",
     *     description="Returns list of Groups",
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items()
     *         ),
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized",
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Forbidden",
     *     )
     * )
     */
    public function index(Request $request)
    {
        $searchQuery = $request->query("q");

        $users = User::where("email", "ilike", "%" . $searchQuery . "%")->pluck("id")->toArray();

        $groups = Group::where("title", "ilike", "%" . $searchQuery . "%")
            ->orWhereIn("owner_id", $users)
            ->orderByRaw("(title ilike ?) DESC, title", ["%" . $searchQuery . "%"])
            ->get();

        foreach ($groups as $group) {
            $membersCount = GroupMember::where('group_id', $group->id)->count();
            $group->members_count = $membersCount;

            $postsCount = Post::where("group_id", $group->id)->count();
            $group->posts_count = $postsCount;

            $owner = User::find($group->owner_id);
            $group->owner_email = $owner ? $owner->email : "Unknown";
        }

        $data = [
            'status' => 200,
            'groups' => $groups
        ];

        return response()->json($data, 200);
    }

    //User GetGroup
    /**
     * @OA\Get(
     *     path="/api/group/mygroups",
     *     operationId="UsergetMyGroups",
     *     tags={"UserGroup"},
     *     summary="Get my groups",
     *     description="Returns a list of groups",
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items()
     *         ),
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized",
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Forbidden",
     *     )
     * )
     */
    public function getMyGroups(Request $request)
    {
        $user = Auth::user();

        $status = $request->query('status');

        $type = $request->query("type");
        $groups = [];

        if ($status != "public" && $status != "private") {
            if ($type == "my-group") {
                $groups = Group::where("owner_id", $user->id)->where("title", "ilike", "%" . $request->query("search") . "%")->get();
            } else {
                if ($type == "joined-group") {

                    $joinedGroupIds = GroupMember::where("user_id", $user->id)->get();

                    $joinedGroups = [];

                    foreach ($joinedGroupIds as $joinedGroup) {
                        $group = Group::where("id", $joinedGroup->group_id)->whereNot("owner_id", $user->id)->where("title", "ilike", "%" . $request->query("search") . "%")->first();

                        if ($group) {
                            array_push($joinedGroups, $group);
                        }
                    }

                    $groups = $joinedGroups;
                } else {
                    $joinedGroupIds = GroupMember::where("user_id", $user->id)->get();

                    $joinedGroups = [];

                    foreach ($joinedGroupIds as $joinedGroup) {
                        $group = Group::where("id", $joinedGroup->group_id)->where("title", "ilike", "%" . $request->query("search") . "%")->first();

                        if ($group) {
                            array_push($joinedGroups, $group);
                        }
                    }
                    $groups = $joinedGroups;
                }
            }
        } else {

            if ($type == "my-group") {
                $groups = Group::where("owner_id", $user->id)->where("title", "ilike", "%" . $request->query("search") . "%")->where("status", $status)->get();
            } else {
                if ($type == "joined-group") {

                    $joinedGroupIds = GroupMember::where("user_id", $user->id)->get();

                    $joinedGroups = [];

                    foreach ($joinedGroupIds as $joinedGroup) {
                        $group = Group::where("id", $joinedGroup->group_id)->whereNot("owner_id", $user->id)->where("title", "ilike", "%" . $request->query("search") . "%")->where("status", $status)->first();

                        if ($group) {
                            array_push($joinedGroups, $group);
                        }
                    }

                    $groups = $joinedGroups;
                } else {
                    $joinedGroupIds = GroupMember::where("user_id", $user->id)->get();

                    $joinedGroups = [];

                    foreach ($joinedGroupIds as $joinedGroup) {
                        $group = Group::where("id", $joinedGroup->group_id)->where("title", "ilike", "%" . $request->query("search") . "%")->where("status", $status)->first();

                        if ($group) {
                            array_push($joinedGroups, $group);
                        }
                    }
                    $groups = $joinedGroups;
                }
            }

        }

        foreach ($groups as $group) {

            $groupAdmin = GroupMember::where("group_id", $group->id)->where("user_id", $user->id)->where("role", "admin")->first();
            if (!$groupAdmin) {
                continue;
            }

            $reqs = GroupRequest::where("group_id", $group->id)->get();
            $group["req_count"] = count($reqs);
        }

        $data = [
            'status' => 200,
            'groups' => $groups
        ];

        return response()->json($data, 200);
    }

    //User GetUserGroup
    /**
     * @OA\Get(
     *     path="/api/group/user/{id}",
     *     operationId="UsergetUserGroups",
     *     tags={"UserGroup"},
     *     summary="Get groups by user ID",
     *     description="Returns a list of groups owned by a specific user",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(
     *             type="integer"
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items()
     *         ),
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized",
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Forbidden",
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Not found",
     *     )
     * )
     */

    public function getUserGroups($id)
    {
        $group = Group::where('owner_id', $id)->get();

        // loop each group to get the members count
        foreach ($group as $g) {
            $members = GroupMember::where("group_id", $g->id)->get();
            $membersCount = $members->count();
            $g->members = $membersCount;
        }

        $data = [
            'status' => 200,
            'group' => $group
        ];

        return response()->json($data, 200);
    }

    /**
     * Store a newly created resource in storage.
     */

    /**
     * @OA\Post(
     *     path="/api/group",
     *     operationId="storeGroup",
     *     tags={"UserGroup"},
     *     summary="Create Group ",
     *     description="Creates a Group ",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(
     *             type="integer"
     *         )
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             required={"owner_id", "title", "status", "group_id", "user_id"},
     *             @OA\Property(property="owner_id", type="integer"),
     *             @OA\Property(property="title", type="string"),
     *             @OA\Property(property="status", type="string"),
     *             @OA\Property(property="group_id", type="integer"),
     *             @OA\Property(property="user_id", type="integer")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Request created successfully",
     *         @OA\JsonContent()
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="User already in group or already requested",
     *         @OA\JsonContent()
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Group not found",
     *         @OA\JsonContent()
     *     )
     * )
     */

    public function store(StoreGroupRequest $request)
    {
        $user = Auth::user();
        $userId = $user->id;

        $validator = Validator::make($request->all(), [
            'title' => 'required',
            "img_url" => "nullable|url",
            'status' => 'required',
        ]);



        if ($validator->fails()) {

            $data = [
                "status" => 400,
                "message" => $validator->messages()
            ];

            return response()->json($data, 400);

        } else {
            $group = new Group;

            $group->owner_id = $userId;
            $group->title = $request->title;
            $group->status = $request->status;
            $group->img_url = $request->img_url;

            $group->save();

            $owner = new GroupMember;

            $owner->group_id = $group->id;
            $owner->user_id = $userId;
            $owner->role = "admin";

            $owner->save();

            $data = [
                "status" => 200,
                "message" => "Group created successfully",
                "id" => $group->id,
            ];

            return response()->json($data, 200);
        }
    }

    /**
     * Display the specified resource.
     */

    /**
     * @OA\Get(
     *     path="/api/group/{id}",
     *     operationId="getGroupById",
     *     tags={"UserGroup"},
     *     summary="Get Group information",
     *     description="Returns Group data",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(
     *             type="integer"
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\JsonContent()
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized",
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Forbidden",
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="GroupInvite not found",
     *     )
     * )
     */
    public function show($id)
    {
        $user = Auth::user();
        $group = Group::find($id);

        if (!$group) {
            $data = [
                "status" => 404,
                "message" => "Group not found",
            ];

            return response()->json($data, 404);
        }

        $isMember = false;
        if ($group->owner_id == $user->id) {
            $isMember = true;
        } else {
            $member = GroupMember::where("group_id", $id)->where("user_id", $user->id)->first();
            if ($member) {
                $isMember = true;
            }
        }

        $isAdmin = false;
        if ($group->owner_id == $user->id) {
            $isAdmin = true;
        } else {
            $member = GroupMember::where("group_id", $id)->where("user_id", $user->id)->first();
            if ($member) {
                if ($member->role == "admin") {
                    $isAdmin = true;
                }
            }
        }

        $members = GroupMember::where("group_id", $id)->get();
        $membersCount = $members->count();

        $post = Post::where("group_id", $id)->get();



        $res = [
            "id" => $group->id,
            "title" => $group->title,
            "owner_id" => $group->owner_id,
            "img_url" => $group->img_url,
            "is_member" => $isMember,
            "is_admin" => $isAdmin,
            "status" => $group->status,
            "members" => $membersCount,
            "posts" => count($post),
            "created_at" => $group->created_at,
            "updated_at" => $group->updated_at,
        ];

        if ($group->status == "private") {
            $req = GroupRequest::where("group_id", $id)->where("user_id", $user->id)->first();

            if ($req) {
                $res["is_requesting"] = true;
            } else {
                $res["is_requesting"] = false;
            }
        }

        $isInviting = GroupInvite::where("group_id", $id)->where("user_id", $user->id)->first();
        if ($isInviting) {
            $res["is_inviting"] = true;
            $res["invite_id"] = $isInviting->id;
        } else {
            $res["is_inviting"] = false;
        }

        $data = [
            "status" => 200,
            "group" => $res,
        ];

        return response()->json($data, 200);
    }

    /**
     * Update the specified resource in storage.
     */
    /**
     * Update the specified resource in storage.
     * @OA\Put(
     *     path="/api/group/{id}",
     *     operationId="updateGroup",
     *     tags={"UserGroup"},
     *     summary="Update group ",
     *     description="Updates a specific group ",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(
     *             type="integer"
     *         )
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             required={"status", "role"},
     *             @OA\Property(property="status", type="string"),
     *             @OA\Property(property="title", type="string"),
     *             @OA\Property(property="img_url", type="string", format="url", nullable=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Member updated successfully",
     *         @OA\JsonContent()
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request"
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Unauthorized"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Group not found"
     *     )
     * )
     */
    public function update(UpdateGroupRequest $request, $id)
    {
        $user = Auth::user();

        $group = Group::find($id);

        if (!$group) {
            $data = [
                "status" => 404,
                "message" => "Group not found",
            ];
            return response()->json($data, 404);
        }


        if (!Gate::allows('update', $group)) {
            $data = [
                "status" => 403,
                "message" => "Unauthorized"
            ];

            return response()->json($data, 403);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required',
            'status' => 'required',
            "img_url" => "nullable|url"
        ]);

        if ($validator->fails()) {
            $data = [
                "status" => 400,
                "message" => $validator->messages()
            ];

            return response()->json($data, 400);
        }
        $group = Group::find($id);

        $group->title = $request->title;
        $group->status = $request->status;
        if ($request->img_url != "") {
            $group->img_url = $request->img_url;
        }

        $group->save();

        Post::where("group_id", "=", $group->id)->update(["status" => $request->status]);

        $data = [
            "status" => 200,
            "message" => "Group updated successfully"

        ];

        return response()->json($data, 200);


    }
    //Promote User to be Admin
    /**
     * @OA\Put(
     *     path="/api/group/promote/{id}",
     *     operationId="UserpromoteToAdmin",
     *     tags={"UserGroup"},
     *     summary="Promote member to admin",
     *     description="Promotes a member to admin role",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(
     *             type="integer"
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Member promoted to admin successfully",
     *         @OA\JsonContent()
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Group not found"
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized"
     *     )
     * )
     */

    public function promoteToAdmin($id)
    {
        $auth = Auth::user();

        $member = GroupMember::find($id);
        if (!$member) {
            return response()->json([
                "status" => 404,
                "message" => "Member not found"
            ], 404);
        }

        $group = Group::find($member->group_id);

        $isOwner = Group::where("id", $group->id)->where("owner_id", $auth->id)->first();
        if (!$isOwner) {
            return response()->json([
                "status" => 401,
                "message" => "Unauthorized"
            ], 401);
        }
        //change group member role with id to admin
        $member->role = "admin";
        $member->save();

        $data = [
            "status" => 200,
            "message" => "Member promoted to admin successfully"
        ];
        return response()->json($data, 200);
    }

    //User Demote to Admin
    /**
     * @OA\Put(
     *     path="/api/group/demote/{id}",
     *     operationId="UserdemoteAdmin",
     *     tags={"UserGroup"},
     *     summary="Demote admin to member",
     *     description="Demotes an admin to member role",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(
     *             type="integer"
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Member demoted to user successfully",
     *       @OA\JsonContent(
     *             @OA\Property(property="status", type="integer"),
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Member not found",
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized"
     *     )
     * )
     */
    public function demoteAdmin($id)
    {
        $auth = Auth::user();

        $member = GroupMember::find($id);
        if (!$member) {
            return response()->json([
                "status" => 404,
                "message" => "Member not found"
            ], 404);
        }

        $group = Group::find($member->group_id);

        $isOwner = Group::where("id", $group->id)->where("owner_id", $auth->id)->first();
        if (!$isOwner) {
            return response()->json([
                "status" => 401,
                "message" => "Unauthorized"
            ], 401);
        }
        //change group member role with id to admin
        $member->role = "member";
        $member->save();

        $data = [
            "status" => 200,
            "message" => "Member demoted to user successfully"
        ];
        return response()->json($data, 200);
    }
    /**
     * Remove the specified resource from storage.
     *
     * @OA\Delete(
     *     path="/api/group/{id}",
     *     operationId="UserdeleteGroup",
     *     tags={"UserGroup"},
     *     summary="Delete group",
     *     description="Deletes a specific group",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(
     *             type="integer"
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Group deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="integer"),
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request"
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Unauthorized"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Group not found"
     *     )
     * )
     */
    public function destroy(Request $request, $id)
    {

        // validate password

        $user = Auth::user();

        $validator = Validator::make(request()->all(), [
            'password' => 'required'
        ]);

        if ($validator->fails()) {
            $data = [
                "status" => 400,
                "message" => $validator->messages()
            ];

            return response()->json($data, 400);
        }

        //check password
        if (!Hash::check(request()->password, $user->password)) {
            $data = [
                "status" => 401,
                "message" => "Password is incorrect"
            ];

            return response()->json($data, 401);
        }


        $group = Group::find($id);

        if (!$group) {
            $data = [
                "status" => 404,
                "message" => "Group with id: $id is not found",
            ];

            return response()->json($data, 404);
        }

        if (!Gate::allows('delete', $group)) {
            $data = [
                "status" => 403,
                "message" => "Unauthorized"
            ];

            return response()->json($data, 403);
        }

        $group->delete();

        GroupMember::where("group_id", "=", $group->id)->delete();

        Post::where("group_id", "=", $group->id)->delete();

        $data = [
            "status" => 200,
            "message" => "Group deleted successfully",
        ];

        return response()->json($data, 200);
    }

    /**
     * @OA\Put(
     *     path="/api/group/public/join/{id}",
     *     operationId="UserjoinPublicGroup",
     *     tags={"UserGroup"},
     *     summary="Join a public group",
     *     description="Allows a user to join a public group",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(
     *             type="integer"
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="You have joined the group successfully",
     *          @OA\JsonContent(
     *             @OA\Property(property="status", type="integer"),
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Group not found",
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="You are already a member of this group",
     *     )
     * )
     */
    public function joinPublicGroup($id)
    {
        $user = Auth::user();
        $userId = $user->id;

        $group = Group::find($id);

        if (!$group) {
            $data = [
                "status" => 404,
                "message" => "Group not found",
            ];

            return response()->json($data, 404);
        }

        $member = GroupMember::where("group_id", $id)->where("user_id", $userId)->first();

        if ($member) {
            $data = [
                "status" => 400,
                "message" => "You are already a member of this group"
            ];

            return response()->json($data, 400);
        }

        $member = new GroupMember;

        $member->group_id = $id;
        $member->user_id = $userId;
        $member->role = "member";

        $member->save();

        $data = [
            "status" => 200,
            "message" => "You have joined the group successfully"
        ];

        return response()->json($data, 200);
    }


    /**
     * @OA\Put(
     *     path="/api/group/leave/{id}",
     *     operationId="UserleaveGroup",
     *     tags={"UserGroup"},
     *     summary="Leave a group",
     *     description="Allows a user to leave a group",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(
     *             type="integer"
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="You have left the group successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="integer"),
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Group not found",
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="You are not a member of this group",
     *     )
     * )
     */
    public function leaveGroup($id)
    {
        $user = Auth::user();
        $userId = $user->id;

        $group = Group::find($id);

        if (!$group) {
            $data = [
                "status" => 404,
                "message" => "Group not found",
            ];

            return response()->json($data, 404);
        }

        $member = GroupMember::where("group_id", $id)->where("user_id", $userId)->first();

        if (!$member) {
            $data = [
                "status" => 400,
                "message" => "You are not a member of this group"
            ];

            return response()->json($data, 400);
        }

        $member->delete();

        $data = [
            "status" => 200,
            "message" => "You have left the group successfully"
        ];

        return response()->json($data, 200);
    }

    public function transferGroupOwnership(Request $request, $id)
    {
        $user = Auth::user();
        $userId = $user->id;


        $group = Group::find($id);

        if (!$group) {
            $data = [
                "status" => 404,
                "message" => "Group not found",
            ];

            return response()->json($data, 404);
        }

        $owner = Group::where("id", $id)->where("owner_id", $userId)->first();

        if (!$owner) {
            $data = [
                "status" => 400,
                "message" => "You are not the owner of this group"
            ];

            return response()->json($data, 400);
        }

        $validator = Validator::make(request()->all(), [
            'new_owner_id' => 'required|integer',
            'password' => 'required'
        ]);

        if ($validator->fails()) {
            $data = [
                "status" => 400,
                "message" => $validator->messages()
            ];

            return response()->json($data, 400);
        }

        //check password
        if (!Hash::check(request()->password, $user->password)) {
            $data = [
                "status" => 401,
                "message" => "Password is incorrect"
            ];

            return response()->json($data, 401);
        }

        $newOwnerId = request()->new_owner_id;

        $newOwner = User::find($newOwnerId);

        if (!$newOwner) {
            $data = [
                "status" => 404,
                "message" => "New owner not found"
            ];

            return response()->json($data, 404);
        }

        $group->owner_id = $newOwnerId;
        $group->save();

        $member = GroupMember::where("group_id", $id)->where("user_id", $newOwnerId)->first();

        if (!$member) {
            $member = new GroupMember;

            $member->group_id = $id;
            $member->user_id = $newOwnerId;
            $member->role = "admin";

            $member->save();
        } else {
            $member->role = "admin";
            $member->save();
        }


        // send mail

        $details = [
            'group_id' => $group->id,
            'group_name' => $group->title,
            "toEmail" => $newOwner->email,
            "fromEmail" => $user->email,
            "first_name" => $newOwner->first_name,
        ];

        Mail::to($details['toEmail'])->send(new SendTransferMail($details));



        $data = [
            "status" => 200,
            "message" => "Group ownership transferred successfully"
        ];

        return response()->json($data, 200);
    }
}
