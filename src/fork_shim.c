/**
 * qbix_fork.dll — Windows COW fork shim for Qbix Server
 * 
 * Exposes a Unix-like fork() using the undocumented RtlCloneUserProcess
 * from ntdll.dll. The cloned process inherits all memory pages as
 * copy-on-write, exactly like pcntl_fork() on Linux/macOS.
 *
 * Build (MSVC):
 *   cl /LD /O2 fork_shim.c /Fe:qbix_fork.dll
 *
 * Build (MinGW):
 *   gcc -shared -O2 -o qbix_fork.dll fork_shim.c
 */

#ifdef _WIN32

#include <windows.h>

/* NT status codes */
#define STATUS_SUCCESS          ((long)0x00000000)
#define STATUS_PROCESS_CLONED   ((long)0x00000129)

/* Clone flags */
#define RTL_CLONE_PROCESS_FLAGS_CREATE_SUSPENDED  0x00000001
#define RTL_CLONE_PROCESS_FLAGS_INHERIT_HANDLES   0x00000002

/* Minimal CLIENT_ID */
typedef struct {
    HANDLE UniqueProcess;
    HANDLE UniqueThread;
} QBIX_CLIENT_ID;

/* SECTION_IMAGE_INFORMATION — we only need the struct to be big enough */
typedef struct {
    PVOID TransferAddress;
    ULONG ZeroBits;
    SIZE_T MaximumStackSize;
    SIZE_T CommittedStackSize;
    ULONG SubSystemType;
    ULONG SubSystemVersion;
    ULONG GpValue;
    USHORT ImageCharacteristics;
    USHORT DllCharacteristics;
    USHORT Machine;
    UCHAR ImageContainsCode;
    UCHAR _spare;
    ULONG LoaderFlags;
    ULONG ImageFileSize;
    ULONG CheckSum;
} QBIX_SECTION_IMAGE_INFORMATION;

/* RTL_USER_PROCESS_INFORMATION */
typedef struct {
    ULONG Length;
    HANDLE Process;
    HANDLE Thread;
    QBIX_CLIENT_ID ClientId;
    QBIX_SECTION_IMAGE_INFORMATION ImageInformation;
} QBIX_RTL_USER_PROCESS_INFORMATION;

/* RtlCloneUserProcess prototype */
typedef long (NTAPI *RtlCloneUserProcess_t)(
    ULONG ProcessFlags,
    PVOID ProcessSecurityDescriptor,
    PVOID ThreadSecurityDescriptor,
    HANDLE DebugPort,
    QBIX_RTL_USER_PROCESS_INFORMATION *ProcessInformation
);

static RtlCloneUserProcess_t pRtlCloneUserProcess = NULL;
static int initialized = 0;

static int init_fork(void) {
    if (initialized) return pRtlCloneUserProcess != NULL;
    initialized = 1;
    HMODULE ntdll = GetModuleHandleA("ntdll.dll");
    if (!ntdll) return 0;
    pRtlCloneUserProcess = (RtlCloneUserProcess_t)
        GetProcAddress(ntdll, "RtlCloneUserProcess");
    return pRtlCloneUserProcess != NULL;
}

/**
 * qbix_fork() — Unix-like fork() for Windows.
 *
 * Returns:
 *   > 0  in the parent (child's PID)
 *     0  in the child
 *    -1  on error
 *
 * The child process is a COW clone of the parent. All memory pages
 * are shared until written to. File handles are inherited.
 *
 * Caveats:
 *   - Only the calling thread survives in the child
 *   - GUI/GDI calls will crash in the child
 *   - The API is undocumented and may change between Windows versions
 *   - Some antivirus may flag this behavior
 */
__declspec(dllexport) int qbix_fork(void) {
    if (!init_fork()) return -1;

    QBIX_RTL_USER_PROCESS_INFORMATION info;
    ZeroMemory(&info, sizeof(info));
    info.Length = sizeof(info);

    long status = pRtlCloneUserProcess(
        RTL_CLONE_PROCESS_FLAGS_INHERIT_HANDLES,
        NULL, NULL, NULL, &info
    );

    if (status == STATUS_PROCESS_CLONED) {
        /* We are the child */
        return 0;
    }

    if (status == STATUS_SUCCESS) {
        /* We are the parent — resume the child's main thread */
        DWORD childPid = (DWORD)(ULONG_PTR)info.ClientId.UniqueProcess;
        ResumeThread(info.Thread);
        CloseHandle(info.Thread);
        CloseHandle(info.Process);
        return (int)childPid;
    }

    /* Fork failed */
    return -1;
}

/**
 * qbix_fork_available() — check if fork is available on this system.
 * Returns 1 if RtlCloneUserProcess was found in ntdll.dll, 0 otherwise.
 */
__declspec(dllexport) int qbix_fork_available(void) {
    return init_fork();
}

/**
 * qbix_getpid() — get current process ID.
 */
__declspec(dllexport) int qbix_getpid(void) {
    return (int)GetCurrentProcessId();
}

/**
 * qbix_waitpid() — wait for a child process.
 * Returns the child's exit code, or -1 on error.
 * If timeout_ms is 0, returns immediately (WNOHANG equivalent).
 */
__declspec(dllexport) int qbix_waitpid(int pid, int timeout_ms) {
    HANDLE proc = OpenProcess(SYNCHRONIZE | PROCESS_QUERY_INFORMATION, FALSE, (DWORD)pid);
    if (!proc) return -1;

    DWORD result = WaitForSingleObject(proc, timeout_ms == 0 ? 0 : (DWORD)timeout_ms);
    if (result == WAIT_OBJECT_0) {
        DWORD exitCode = 0;
        GetExitCodeProcess(proc, &exitCode);
        CloseHandle(proc);
        return (int)exitCode;
    }
    CloseHandle(proc);
    return (result == WAIT_TIMEOUT) ? -2 : -1;  /* -2 = still running */
}

#endif /* _WIN32 */
