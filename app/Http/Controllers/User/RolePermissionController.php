<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Http\Request;

class RolePermissionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function getRoles()
    {
        $roles = Role::all();
        return response()->json(['role' => $roles], 200);
    }
    public function createRole(Request $request)
    {
        // return response()->json([$request->all()], 200);
        // exit;
        $data = $request->validate([
            'name' => 'required|string|unique:roles,name',
            // Add other validation rules if needed
        ]);
        $role = new Role();
        $role->name = $request->name;
        $role->guard_name = 'api';
        $role->save();
        return response()->json(['message' => 'Role created successfully', 'role' => $role], 201);
    }
    public function EditRole(string $id)
    {
        $roles = Role::find($id);
        return response()->json(['role' => $roles], 200);
    }

    public function UpdateRole(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|unique:roles,name',
            // Add other validation rules if needed
        ]);
        $role = Role::find($request->id);
        $role->name = $request->name;
        $role->guard_name = 'api';
        $role->save();
        return response()->json([''=>true,'message' => 'Role Updated successfully', 'role' => $role], 201);
    }

    public function changeViewerRole($id) {
        $user = User::findOrFail($id);
        $user->roles()->detach();
        $role = Role::where('name', 'User')->first();

        if ($role) {
            $user->assignRole($role);
        }
        $user->save();

        return response()->json(['status'=>true,'role'=>$user]);
    }


    // permission
    public function getPermissions()
    {
        $Permissions = Permission::all();
        return response()->json(['Permission' => $Permissions], 200);
    }
    public function createPermission(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|unique:permissions,name',
        ]);

        $permission = new Permission();
        $permission->name = $request->name;
        $permission->guard_name = 'api';
        $permission->save();

        return response()->json(['message' => 'Permission created successfully', 'Permission' => $permission], 201);
    }
    public function EditPermission(string $id)
    {
        $Permissions = Permission::find($id);
        return response()->json(['Permission' => $Permissions], 200);
    }

    public function UpdatePermission(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|unique:Permissions,name',
            // Add other validation rules if needed
        ]);
        print_r($request->all());
        // exit;
        $permission = Permission::find($request->id);
        if ($permission) {
            $permission->name = $request->name;
            $permission->guard_name = 'api';
            $permission->save();

            return response()->json(['message' => 'Permission updated successfully', 'permission' => $permission], 201);
        } else {
            return response()->json(['message' => 'Permission not found'], 404);
        }
    }
    /**
     * Show the form for creating a new resource.
     */
    public function getRolePermissions($id)
    {
        $role = Role::find($id);
        $permissions = $role->permissions->pluck('id');
        // $permissionsName = $role->permissions->pluck('name');
        // print_r($permissions);
        // exit;
        $permission = Permission::all();
        return response()->json(['id' => $id, 'AllPermission' => $permission, 'exist' => $permissions], 200);
    }
    public function giveRolePermissions(Request $request, $id)
    {
        $permission_ids = $request->permission_id;
        $role = Role::findById($request->id);
        if ($role) {
            // Assign the permissions to the role
            $role->syncPermissions([]);
            $permissions = Permission::whereIn('id', $permission_ids)->get();
            foreach ($permissions as $permission) {
                $role->givePermissionTo($permission);
            }

            // The role now has the specified permissions
            return response()->json(['status'=>true ,'message' => 'Permissions assigned successfully']);
        } else {
            return response()->json(['status'=>false ,'message' => 'Role not found'], 404);
        }

        // return response()->json(['id'=>$id,'AllPermission'=>$request->all()],200);
    }



    public function getUserPermissions($id)
    {
        $user = User::find($id);
        $userPermissions = $user->permissions->pluck('id');

        // Retrieve permissions inherited from user roles
        $rolePermissions = $user->getAllPermissions()->pluck('id');

        // Merge both sets of permissions and remove duplicates
        $permissions = $userPermissions->merge($rolePermissions)->unique();
        $permission = Permission::all();
        return response()->json(['id' => $id, 'AllPermission' => $permission, 'exist' => $permissions], 200);
    }
    public function giveUserPermissions(Request $request, $id)
    {
        $permission_ids = $request->permission_id;
        $user = User::findOrFail($request->user_id);
        if ($user) {
            // Assign the permissions to the user
            $user->syncPermissions([]);
            $permissions = Permission::whereIn('id', $permission_ids)->get();
            foreach ($permissions as $permission) {
                $user->givePermissionTo($permission);
            }

            // The role now has the specified permissions
          return response()->json(['status'=>true ,'message' => 'Permissions assigned successfully']);
        } else {
            return response()->json(['status'=>false ,'message' => 'User not found'], 404);
        }
        // return response()->json(['id'=>$id,'AllPermission'=>$request->all()],200);
    }

}
