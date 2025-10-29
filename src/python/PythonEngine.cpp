#include "otpch.h"

#include "python/PythonEngine.h"

#include "utils/Logger.h"

#include <boost/algorithm/string.hpp>
#include <fmt/format.h>

#ifdef WITH_PYTHON
#define PY_SSIZE_T_CLEAN
#include <Python.h>
#endif

namespace {

std::string sanitizeModuleName(std::string entry) {
        entry = boost::algorithm::trim_copy(entry);
        if (entry.empty()) {
                return entry;
        }

        if (boost::algorithm::iends_with(entry, ".py")) {
                entry.resize(entry.size() - 3);
        }

        std::replace(entry.begin(), entry.end(), '\\', '/');
        for (char& ch : entry) {
                if (ch == '/') {
                        ch = '.';
                }
        }

        return entry;
}

#ifdef WITH_PYTHON
PyObject* toPyObject(void* value) {
        return static_cast<PyObject*>(value);
}

PyThreadState* toThreadState(void* value) {
        return static_cast<PyThreadState*>(value);
}

wchar_t* toWide(void* value) {
        return static_cast<wchar_t*>(value);
}
#endif

} // namespace

#ifdef WITH_PYTHON
extern void RegisterPyBindings();
#else
void RegisterPyBindings() {}
#endif

PythonEngine& PythonEngine::instance() {
        static PythonEngine engine;
        return engine;
}

bool PythonEngine::ensureInitialized() const {
#ifdef WITH_PYTHON
        return ready_ && Py_IsInitialized();
#else
        return false;
#endif
}

bool PythonEngine::init(const std::string& home, const std::string& modulePath, const std::string& entryScript) {
#ifdef WITH_PYTHON
        if (ready_) {
                return true;
        }

        modulePath_ = modulePath;
        entryScript_ = entryScript;
        entryModule_ = sanitizeModuleName(entryScript_);
        if (entryModule_.empty()) {
                Logger::instance().error("[Python] Invalid entry module name");
                return false;
        }

        if (!home.empty()) {
                wchar_t* decoded = Py_DecodeLocale(home.c_str(), nullptr);
                if (!decoded) {
                        Logger::instance().error(fmt::format("[Python] Failed to decode pythonHome '{}'", home));
                        return false;
                }
                Py_SetPythonHome(decoded);
                pythonHome_ = decoded;
        }

        Py_Initialize();
        if (!Py_IsInitialized()) {
                Logger::instance().error("[Python] Failed to initialize interpreter");
                releasePythonHome();
                return false;
        }

        PyEval_InitThreads();

        if (!modulePath_.empty()) {
                PyObject* sysPath = PySys_GetObject("path");
                if (!sysPath) {
                        Logger::instance().error("[Python] sys.path not available");
                        Py_Finalize();
                        releasePythonHome();
                        return false;
                }

                PyObject* pathEntry = PyUnicode_FromString(modulePath_.c_str());
                if (!pathEntry) {
                        if (PyErr_Occurred()) {
                                PyErr_Print();
                        }
                        Logger::instance().error(fmt::format("[Python] Failed to create module path '{}'", modulePath_));
                        Py_Finalize();
                        releasePythonHome();
                        return false;
                }

                if (PyList_Append(sysPath, pathEntry) != 0) {
                        if (PyErr_Occurred()) {
                                PyErr_Print();
                        }
                        Logger::instance().error(fmt::format("[Python] Failed to append '{}' to sys.path", modulePath_));
                        Py_DECREF(pathEntry);
                        Py_Finalize();
                        releasePythonHome();
                        return false;
                }
                Py_DECREF(pathEntry);
        }

        RegisterPyBindings();

        if (!importBootstrapLocked()) {
                Py_Finalize();
                releasePythonHome();
                return false;
        }

        mainThreadState_ = PyEval_SaveThread();
        ready_ = true;
        Logger::instance().info("[Python] Embedded runtime initialized");
        return true;
#else
        (void)home;
        (void)modulePath;
        (void)entryScript;
        return false;
#endif
}

bool PythonEngine::importBootstrap() {
#ifdef WITH_PYTHON
        if (!ensureInitialized()) {
                return false;
        }

        PyGILState_STATE gil = PyGILState_Ensure();
        bool result = importBootstrapLocked();
        PyGILState_Release(gil);
        return result;
#else
        return false;
#endif
}

bool PythonEngine::importBootstrapLocked() {
#ifdef WITH_PYTHON
        if (entryModule_.empty()) {
                Logger::instance().error("[Python] Entry module not configured");
                return false;
        }

        PyObject* module = PyImport_ImportModule(entryModule_.c_str());
        if (!module) {
                if (PyErr_Occurred()) {
                        PyErr_Print();
                }
                Logger::instance().error(fmt::format("[Python] Import error for '{}'", entryModule_));
                return false;
        }

        Py_XDECREF(toPyObject(bootstrapModule_));
        bootstrapModule_ = module;
        return true;
#else
        return false;
#endif
}

void PythonEngine::shutdown() {
#ifdef WITH_PYTHON
        if (!Py_IsInitialized()) {
                bootstrapModule_ = nullptr;
                mainThreadState_ = nullptr;
                ready_ = false;
                releasePythonHome();
                return;
        }

        if (mainThreadState_) {
                PyEval_RestoreThread(toThreadState(mainThreadState_));
                mainThreadState_ = nullptr;
        }

        Py_XDECREF(toPyObject(bootstrapModule_));
        bootstrapModule_ = nullptr;

        Py_Finalize();
        ready_ = false;
        releasePythonHome();
#endif
}

bool PythonEngine::reload() {
#ifdef WITH_PYTHON
        if (!ensureInitialized()) {
                return false;
        }

        PyGILState_STATE gil = PyGILState_Ensure();
        bool result = false;
        if (!bootstrapModule_) {
                result = importBootstrapLocked();
        } else {
                PyObject* reloaded = PyImport_ReloadModule(toPyObject(bootstrapModule_));
                if (!reloaded) {
                        if (PyErr_Occurred()) {
                                PyErr_Print();
                        }
                        Logger::instance().error(fmt::format("[Python] Reload failed for '{}'", entryModule_));
                } else {
                        Py_DECREF(toPyObject(bootstrapModule_));
                        bootstrapModule_ = reloaded;
                        result = true;
                }
        }
        PyGILState_Release(gil);
        return result;
#else
        return false;
#endif
}

bool PythonEngine::call(const char* funcName) {
        if (!funcName) {
                return false;
        }
        return call(funcName, {});
}

bool PythonEngine::call(const char* funcName, const std::vector<std::string>& args) {
#ifdef WITH_PYTHON
        if (!funcName || !ensureInitialized() || !bootstrapModule_) {
                return false;
        }

        PyGILState_STATE gil = PyGILState_Ensure();
        PyObject* func = PyObject_GetAttrString(toPyObject(bootstrapModule_), funcName);
        if (!func) {
                if (PyErr_Occurred()) {
                        PyErr_Print();
                }
                Logger::instance().error(fmt::format("[Python] Function '{}' not found", funcName));
                PyGILState_Release(gil);
                return false;
        }

        if (!PyCallable_Check(func)) {
                Logger::instance().error(fmt::format("[Python] Attribute '{}' is not callable", funcName));
                Py_DECREF(func);
                PyGILState_Release(gil);
                return false;
        }

        PyObject* tuple = PyTuple_New(static_cast<Py_ssize_t>(args.size()));
        if (!tuple) {
                if (PyErr_Occurred()) {
                        PyErr_Print();
                }
                Logger::instance().error("[Python] Failed to allocate argument tuple");
                Py_DECREF(func);
                PyGILState_Release(gil);
                return false;
        }

        for (Py_ssize_t i = 0; i < static_cast<Py_ssize_t>(args.size()); ++i) {
                PyObject* value = PyUnicode_FromString(args[static_cast<size_t>(i)].c_str());
                if (!value) {
                        if (PyErr_Occurred()) {
                                PyErr_Print();
                        }
                        Logger::instance().error("[Python] Failed to convert argument to Unicode");
                        Py_DECREF(tuple);
                        Py_DECREF(func);
                        PyGILState_Release(gil);
                        return false;
                }

                if (PyTuple_SetItem(tuple, i, value) != 0) {
                        if (PyErr_Occurred()) {
                                PyErr_Print();
                        }
                        Logger::instance().error("[Python] Failed to assign argument in tuple");
                        Py_DECREF(value);
                        Py_DECREF(tuple);
                        Py_DECREF(func);
                        PyGILState_Release(gil);
                        return false;
                }
        }

        PyObject* result = PyObject_CallObject(func, tuple);
        Py_DECREF(tuple);
        Py_DECREF(func);

        if (!result) {
                if (PyErr_Occurred()) {
                        PyErr_Print();
                }
                Logger::instance().error(fmt::format("[Python] Call to '{}' failed", funcName));
                PyGILState_Release(gil);
                return false;
        }

        Py_DECREF(result);
        PyGILState_Release(gil);
        return true;
#else
        (void)funcName;
        (void)args;
        return false;
#endif
}

void PythonEngine::onServerStart() {
        (void)call("on_server_start");
}

void PythonEngine::onServerStop() {
        (void)call("on_server_stop");
}

void PythonEngine::onPlayerLogin(uint32_t guid, const std::string& name) {
        (void)guid;
        (void)call("on_player_login", {name});
}

void PythonEngine::onPlayerLogout(uint32_t guid, const std::string& name) {
        (void)guid;
        (void)call("on_player_logout", {name});
}

void PythonEngine::onCreatureDeath(const std::string& killer, const std::string& victim) {
        (void)call("on_creature_death", {killer, victim});
}

void PythonEngine::releasePythonHome() {
#ifdef WITH_PYTHON
        if (pythonHome_) {
                PyMem_RawFree(toWide(pythonHome_));
                pythonHome_ = nullptr;
        }
#endif
}
